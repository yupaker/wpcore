<?php
namespace app\World;

use app\Common\Srp6;

/**
 * 世界服务器鉴权
 */
class Authchallenge
{
    /**
     * [Authchallenge 发起验证 SMSG_AUTH_CHALLENGE]
     * ------------------------------------------------------------------------------
     * @author  by.fan <fan3750060@163.com>
     * ------------------------------------------------------------------------------
     * @version date:2019-07-11
     * ------------------------------------------------------------------------------
     */
    public function Authchallenge($fd)
    {
        $Srp6  = new Srp6();
        $seed  = $Srp6->Littleendian($Srp6->_random_number_helper(4)->toHex())->toBytes();
        $seed1 = $Srp6->Littleendian($Srp6->_random_number_helper(16)->toHex())->toBytes();
        $seed2 = $Srp6->Littleendian($Srp6->_random_number_helper(16)->toHex())->toBytes();

        $data     = $seed . $seed1 . $seed2;
        $data     = GetBytes($data);
        $packdata = Worldpacket::encrypter(OpCode::SMSG_AUTH_CHALLENGE, $data);
        $data     = array_merge($packdata, $data);

        WorldServer::$clientparam[$fd]['serverseed'] = $seed;

        return $data;
    }

    /**
     * [AuthSession 解包客户端信息并验证 CMSG_AUTH_SESSION]
     * ------------------------------------------------------------------------------
     * @author  by.fan <fan3750060@163.com>
     * ------------------------------------------------------------------------------
     * @version date:2019-07-16
     * ------------------------------------------------------------------------------
     * @param   [type]          $data [description]
     */
    public function AuthSession($fd, $data)
    {
        $Srp6 = new Srp6();

        $packdata = Worldpacket::decrypter($data);
        $content  = $packdata['content'];

        $packdata = [];

        # omit first 6 bytes, cause 01-02 = packet size, 03-04 = opcode (0x1ED), 05-06 - unknown null-bytes 07-08 - build 09-14 unknown null-bytes
        $next_length = 2;

        // 版本
        $build             = array_slice($content, 0, $next_length);
        $build_0           = $Srp6->BigInteger(ToStr([$build[1]]), 256)->toHex();
        $build_1           = $Srp6->BigInteger(ToStr([$build[0]]), 256)->toHex();
        $packdata['build'] = HexToDecimal($build_0 . $build_1);
        $next_length += 6;

        // 账户名称
        $account_name_bytes = array_slice($content, $next_length);
        $account_name       = [];
        foreach ($account_name_bytes as $k => $v) {
            if ($v != 0) {
                $account_name[] = $v;
            } else {
                break;
            }
        }
        $packdata['account_name'] = ToStr($account_name);
        $account_name             = $Srp6->BigInteger($packdata['account_name'], 256)->toBytes();
        $next_length              = $next_length + strlen($account_name) + 1;

        //client_seed
        $client_seed             = array_slice($content, $next_length, 4);
        $packdata['client_seed'] = $Srp6->BigInteger(ToStr($client_seed), 256)->toHex();
        $client_seed_Bytes       = $Srp6->BigInteger(ToStr($client_seed), 256)->toBytes();
        $next_length += 4;

        //client_hash
        $client_hash             = array_slice($content, $next_length, 20);
        $packdata['client_hash'] = $Srp6->BigInteger(ToStr($client_hash), 256)->toHex();
        $client_hash_Bytes       = $Srp6->BigInteger(ToStr($client_hash), 256)->toBytes();

        // 查看账户
        $Account  = new \app\Common\Account();
        $userinfo = $Account->get_account($packdata['account_name']);

        if (!$userinfo) {

            WORLD_LOG('Sent Auth Response (unknown account): ' . $account_name, 'error');

            // 用户不存在
            $AUTH_UNKNOWN_ACCOUNT = $Srp6->BigInteger(OpCode::AUTH_UNKNOWN_ACCOUNT, 16)->toBytes();
            $data                 = GetBytes($AUTH_UNKNOWN_ACCOUNT);
            $packdata             = Worldpacket::encrypter(OpCode::SMSG_AUTH_RESPONSE, $data);
            $packdata             = array_merge($packdata, $data);

            return ['code' => 4000, 'msg' => 'unknown accoun', 'data' => $packdata];
        }

        WorldServer::$clientparam[$fd]['userinfo'] = $userinfo;

        //K
        WorldServer::$clientparam[$fd]['sessionkey'] = $Srp6->BigInteger($userinfo['sessionkey'], 16)->toBytes();

        //计算Hash
        $server_hash = sha1($account_name . ToStr(PackInt(0, 32)) . $client_seed_Bytes . WorldServer::$clientparam[$fd]['serverseed'] . WorldServer::$clientparam[$fd]['sessionkey']);

        //验证
        if ($Srp6->BigInteger($server_hash, 16)->toHex() != $packdata['client_hash']) {
            WORLD_LOG('Verification failed: ' . $account_name, 'error');
            WORLD_LOG('server_hash: ' . $Srp6->BigInteger($server_hash, 16)->toHex(), 'error');
            WORLD_LOG('client_hash: ' . $packdata['client_hash'], 'error');

            // 鉴权失败
            return ['code' => 4000, 'msg' => 'Verification failed', 'data' => [0, 0, 4]];
        }

        // 鉴权成功
        WORLD_LOG('account : ' . $packdata['account_name'], 'warning');
        WORLD_LOG('server_hash: ' . $Srp6->BigInteger($server_hash, 16)->toHex(), 'warning');
        WORLD_LOG('client_hash: ' . $packdata['client_hash'], 'warning');
        WORLD_LOG('AUTH_OK: Successful verification', 'success');

        return ['code' => 2000, 'msg' => 'Successful verification', 'data' => $userinfo];
    }
}
