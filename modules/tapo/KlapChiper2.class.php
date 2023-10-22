<?php

require DIR_MODULES.'tapo/phpseclib2/vendor/autoload.php';

use phpseclib\Crypt\AES;


class KlapChiper
{
    private $key;
    private $iv;
    public $seq;
    private $sig;

    public function __construct($local_seed, $remote_seed, $user_hash)
    {
        $this->key = $this->_key_derive($local_seed, $remote_seed, $user_hash);
        list($this->iv, $this->seq) = $this->_iv_derive($local_seed, $remote_seed, $user_hash);
        $this->sig = $this->_sig_derive($local_seed, $remote_seed, $user_hash);
    }

    public function encrypt($msg)
    {
        $this->seq = $this->seq + 1;
        if (is_string($msg)) {
            $msg = utf8_encode($msg);
        }
        assert(is_string($msg));

        $cipher = new AES(AES::MODE_CBC);
        $cipher->disablePadding();
        $cipher->setKey($this->key);
        $cipher->setIV($this->_iv_seq());
        $padded_data = $this->pkcs7Pad($msg);
        $ciphertext = $cipher->encrypt($padded_data);
        $seqBytes = pack('N', $this->seq);
        $dataToHash = $this->sig . $seqBytes . $ciphertext;
        $signature = hash('sha256', $dataToHash, true);
        return $signature . $ciphertext;
    }

    public function decrypt($msg)
    {
        $cipher = new AES(AES::MODE_CBC);
        $cipher->disablePadding();
        $cipher->setKey($this->key);
        $cipher->setIV($this->_iv_seq());

        $msg = substr($msg, 32);
        $dp = $cipher->decrypt($msg);
        $plaintextbytes = $this->pkcs7Unpad($dp);
        return utf8_decode($plaintextbytes);
    }

    private function _key_derive($local_seed, $remote_seed, $user_hash)
    {
        $payload = 'lsk' . $local_seed . $remote_seed . $user_hash;
        $key = hash('sha256', $payload, true);
        return substr($key, 0, 16);
    }

    private function _iv_derive($local_seed, $remote_seed, $user_hash)
    {
        $payload = 'iv' . $local_seed . $remote_seed . $user_hash;
        $fulliv = hash('sha256', $payload, true);
        $seq = unpack('N', substr($fulliv, -4))[1];
        return [substr($fulliv, 0, 12), $seq];
    }

    private function _sig_derive($local_seed, $remote_seed, $user_hash)
    {
        $payload = 'ldk' . $local_seed . $remote_seed . $user_hash;
        $sig = hash('sha256', $payload, true);
        return substr($sig, 0, 28);
    }

    private function _iv_seq()
    {
        $seq = pack('N', $this->seq);
        $iv = $this->iv . $seq;
        assert(strlen($iv) == 16);
        return $iv;
    }

    private function pkcs7Pad($data)
    {
        $block_size = 16;
        $padding = $block_size - (strlen($data) % $block_size);
        $char = chr($padding);
        return $data . str_repeat($char, $padding);
    }

    private function pkcs7Unpad($data)
    {
        $pad = ord($data[strlen($data) - 1]);
        if ($pad < 1 || $pad > 16) {
            return $data;
        }
        return substr($data, 0, -$pad);
    }
}

