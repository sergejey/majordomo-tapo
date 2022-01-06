<?php

class p100
{
    private $publicKey;
    private $privateKey;
    private $encodedPassword;
    private $encodedEmail;

    private $b_arr;
    private $b_arr2;
    private $cookieFilename;
    private $cipherMethod = 'aes-128-cbc';
    private $tapo_token = '';
    private $terminalUUID = '';

    function __construct($ip, $email, $password) {
      $this->ipAddress = $ip;
      $this->cookieFilename = ROOT.'cms/cached/tapo_'.$this->ipAddress.'.txt';
      $this->email = $email;
      $this->password = $password;
      $this->terminalUUID = uniqid();

      $this->encryptCredentials();
      $this->createKeyPair();

    }

    function mime_encoder($to_encode) {
        $result = base64_encode($to_encode);
        $counter = 0;
        for($i=75;$i<strlen($result);$i+=76) {
            $result = substr_replace($result, "\r\n", $i+$counter, 0);
            $counter++;
        }
        //dprint("processed $counter:\n".$result,false);
        /*
        count = 0
        for i in range(76, len(encoded_list), 76):
            encoded_list.insert(i + count, '\r\n')
            count += 1
        return ''.join(encoded_list)
         */
        return $result;
    }

    function tpLinkCipherdecrypt($data) {
        $data_raw=base64_decode($data);
        $cipher = $this->cipherMethod;
        $key = implode('',$this->b_arr);
        $iv = implode('',$this->b_arr2);
        $original = openssl_decrypt(base64_decode($data), $cipher, $key, OPENSSL_RAW_DATA|OPENSSL_ZERO_PADDING, $iv);
        $encoder = new pkcs7encoder();
        $data = $encoder->decode($original);
        return $data;
    }

    function tpLinkCipherencrypt($data) {
        include_once "pkcs7encoder.class.php";
        $encoder = new pkcs7encoder();
        $data = $encoder->encode($data);
        $cipher = $this->cipherMethod;
        $key = implode('',$this->b_arr);
        $iv = implode('',$this->b_arr2);
        $ciphertext = openssl_encrypt($data, $cipher, $key, OPENSSL_RAW_DATA|OPENSSL_ZERO_PADDING, $iv);
        return str_replace("\r\n",'',$this->mime_encoder($ciphertext));
    }

    function encryptCredentials() {
        $this->encodedPassword = $this->mime_encoder($this->password);
        $this->encodedEmail = $this->sha_digest_username($this->email);
        $this->encodedEmail = $this->mime_encoder($this->encodedEmail);
    }

    function createKeyPair() {
        $this->privateKey = '';
        $this->publicKey = '';
        $config = array(
            "digest_alg" => "sha512",
            "private_key_bits" => 1024,
            "private_key_type" => OPENSSL_KEYTYPE_RSA,
        );
        $private_key = openssl_pkey_new($config);
        openssl_pkey_export($private_key, $this->privateKey);
        $this->publicKey = openssl_pkey_get_details($private_key)['key'];
        $this->publicKey = preg_replace('/\n$/is','',$this->publicKey);
    }

    function decode_handshake_key($encryptedKey) {

        $data = base64_decode($encryptedKey);

        //dprint("key: (".strlen($data).")".$data,false);

        openssl_private_decrypt($data, $decrypted, $this->privateKey);

        //dprint("decrypted key (".strlen($decrypted)."): ".$decrypted);

        for($i=0;$i<16;$i++) {
            $this->b_arr[$i]=$decrypted[$i];
            $this->b_arr2[$i]=$decrypted[$i+16];
        }
        return true;
    }

    function sha_digest_username($data) {
        $digest = sha1($data);
        return $digest;
    }

    function handshake() {
        $url = 'http://'.$this->ipAddress.'/app';
        $ar = array('method'=>'handshake','params'=>array(
            'key'=>$this->publicKey,
            'requestTimeMils'=>round(microtime(true)*1000)
        ));
        $ch = curl_init( $url );
        $payload = json_encode( $ar );
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt( $ch, CURLOPT_POSTFIELDS, $payload );
        curl_setopt( $ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
        $tmpfname = $this->cookieFilename;
        if (file_exists($tmpfname)) unlink($tmpfname);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $tmpfname);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $tmpfname);
        $result = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($result,true);
        if (!$data['error_code']) {
            $encryptedKey = $data['result']['key'];
            $this->decode_handshake_key($encryptedKey);
            return true;
        } else {
            return false;
        }
    }

    function login() {
        $url = 'http://'.$this->ipAddress.'/app';
        $payload = array(
            'method'=>'login_device',
            'params'=>array(
                'username'=>$this->encodedEmail,
                'password'=>$this->encodedPassword
        ));

        $encryptedPayload = $this->tpLinkCipherencrypt(json_encode($payload));
        $secureRequest = array(
            'method'=>'securePassthrough',
            'params'=>array(
                'request'=>$encryptedPayload
            )
        );

        $ch = curl_init( $url );
        $payload = json_encode( $secureRequest );
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt( $ch, CURLOPT_POSTFIELDS, $payload );
        curl_setopt( $ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
        $tmpfname = $this->cookieFilename;
        curl_setopt($ch, CURLOPT_COOKIEJAR, $tmpfname);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $tmpfname);
        $result = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($result,true);
        if (isset($data['error_code']) && !$data['error_code']) {
            $response = $this->tpLinkCipherdecrypt($data['result']['response']);
            $data = json_decode($response,true);
            if ($data['result']['token']) {
                $this->tapo_token = $data['result']['token'];
                return true;
            }
        }
        return false;
    }

    function turnOn() {
        $url = 'http://'.$this->ipAddress.'/app?token='.$this->tapo_token;
        $payload = array(
            'method'=>'set_device_info',
            'params'=>array(
                'device_on'=>true
            ),
            'requestTimeMils'=>round(microtime(true)*1000),
            'terminalUUID'=>$this->terminalUUID
        );

        $encryptedPayload = $this->tpLinkCipherencrypt(json_encode($payload));
        $secureRequest = array(
            'method'=>'securePassthrough',
            'params'=>array(
                'request'=>$encryptedPayload
            )
        );
        $ch = curl_init( $url );
        $payload = json_encode( $secureRequest );
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt( $ch, CURLOPT_POSTFIELDS, $payload );
        curl_setopt( $ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
        $tmpfname = $this->cookieFilename;
        curl_setopt($ch, CURLOPT_COOKIEJAR, $tmpfname);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $tmpfname);
        $result = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($result,true);
        if (isset($data['error_code']) && !$data['error_code']) {
            $response = $this->tpLinkCipherdecrypt($data['result']['response']);
            $data = json_decode($response,true);
            if (isset($data['error_code']) && !$data['error_code']) {
                return true;
            }
        }
        return false;
    }

    function turnOff() {
        $url = 'http://'.$this->ipAddress.'/app?token='.$this->tapo_token;
        $payload = array(
            'method'=>'set_device_info',
            'params'=>array(
                'device_on'=>false
            ),
            'requestTimeMils'=>round(microtime(true)*1000),
            'terminalUUID'=>$this->terminalUUID
        );

        $encryptedPayload = $this->tpLinkCipherencrypt(json_encode($payload));
        $secureRequest = array(
            'method'=>'securePassthrough',
            'params'=>array(
                'request'=>$encryptedPayload
            )
        );

        $ch = curl_init( $url );
        $payload = json_encode( $secureRequest );
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt( $ch, CURLOPT_POSTFIELDS, $payload );
        curl_setopt( $ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
        $tmpfname = $this->cookieFilename;
        curl_setopt($ch, CURLOPT_COOKIEJAR, $tmpfname);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $tmpfname);
        $result = curl_exec($ch);
        curl_close($ch);
        $data = json_decode($result,true);
        if (isset($data['error_code']) && !$data['error_code']) {
            $response = $this->tpLinkCipherdecrypt($data['result']['response']);
            $data = json_decode($response,true);
            if (isset($data['error_code']) && !$data['error_code']) {
                return true;
            }
        }
        return false;
    }

    function getDeviceInfo() {
        $url = 'http://'.$this->ipAddress.'/app?token='.$this->tapo_token;
        $payload = array(
            'method'=>'get_device_info',
            'requestTimeMils'=>round(microtime(true)*1000)
            );

        $encryptedPayload = $this->tpLinkCipherencrypt(json_encode($payload));
        $secureRequest = array(
            'method'=>'securePassthrough',
            'params'=>array(
                'request'=>$encryptedPayload
            )
        );

        $ch = curl_init( $url );
        $payload = json_encode( $secureRequest );
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt( $ch, CURLOPT_POSTFIELDS, $payload );
        curl_setopt( $ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
        $tmpfname = $this->cookieFilename;
        curl_setopt($ch, CURLOPT_COOKIEJAR, $tmpfname);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $tmpfname);
        $result = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($result,true);
        if (isset($data['error_code']) && !$data['error_code']) {
            $response = $this->tpLinkCipherdecrypt($data['result']['response']);
            $data = json_decode($response,true);
            if (is_array($data['result'])) {
                return $data['result'];
            }
        }
        return false;
    }
}