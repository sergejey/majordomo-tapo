<?php


class TPLinkKlap
{

    const TP_DEFAULT_PORT = 80;
    const TP_DISCOVERY_PORT = 20002;
    const TP_DEFAULT_TIMEOUT = 5;
    const TP_SESSION_COOKIE_NAME = "TP_SESSIONID";
    const KASA_SETUP_EMAIL = "kasa@tp-link.net";
    const KASA_SETUP_PASSWORD = "kasaSetup";

    private $host;
    private $email;
    private $password;

    private $localSeed;
    private $localAuthHash;
    private $localAuthOwner;
    private $handshakeLock;
    private $queryLock;
    private $handshakeDone;
    private $encryptionSession;
    private $timeout;
    private $session;
    private $cookie_file;
    private $session_cookies;
    private $KlapSession;
    private $terminalUUID;

    public function __construct($ip, $email, $password)
    {
        $this->host = $ip;
        $this->email = $email;
        $this->password = $password;

        $this->terminalUUID = uniqid();

        $this->handshakeDone = false;
        $this->timeout = self::TP_DEFAULT_TIMEOUT;
        $this->KlapCipher = false;

        $this->cookie_file = ROOT . 'cms/cached/tplink_cookie_' . $this->host . '.txt';

    }

    private function _d($msg)
    {
        dprint($msg, false);
    }

    private function _sha256($payload)
    {
        return hash('sha256', $payload, true);
    }

    private function _sha1($payload)
    {
        return hash('sha1', $payload, true);
    }

    private function _md5($payload)
    {
        return hash('md5', $payload, true);
    }

    function generate_auth_hash($username, $password)
    {
        return $this->_md5($this->_md5($username) . $this->_md5($password));
    }

    function generate_auth_hash_alt($username, $password)
    {
        return $this->_sha256($this->_sha1($username) . $this->_sha1($password));
    }

    function generate_owner_hash($username, $password)
    {
        return $this->_md5($username);
    }

    private function get_local_seed()
    {
        return random_bytes(16);
        //return '1234567890123456';
    }

    private function clear_cookies()
    {
        $this->session_cookies = array();
        if (file_exists($this->cookie_file)) {
            unlink($this->cookie_file);
        }
    }

    private function handle_cookies()
    {
        if (!file_exists($this->cookie_file)) return;
        $data = LoadFile($this->cookie_file);
        $lines = explode("\n", $data);
        $res_lines = array();
        foreach ($lines as $line) {
            if (!preg_match('/' . $this->host . '/', $line) ||
                preg_match('/' . self::TP_SESSION_COOKIE_NAME . '/', $line)
            ) {
                $res_lines[] = $line;
            }

            if (preg_match('/' . $this->host . '/', $line)) {
                $tmp = explode("\t", $line);
                $this->session_cookies[$tmp[5]] = $tmp[6];
            }
        }
        $result = implode("\n", $res_lines);
        SaveFile($this->cookie_file, $result);
    }

    private function session_post($url, $data)
    {

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10); // connection timeout
        curl_setopt($ch, CURLOPT_TIMEOUT, 45);  // operation timeout 45 seconds
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookie_file);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookie_file);
        $result = curl_exec($ch);
        if (!curl_errno($ch)) {
            $info = curl_getinfo($ch);
            if (!$result && $info['http_code']=='200') $result = 'OK';
            if ($info['http_code']!='200') {
                $this->_d("Post to $url failed with code: ".$info['http_code']);
            }
        }
        curl_close($ch);

        return $result;

    }

    function _print_hash($title, $data = '')
    {
        if (!$data) {
            $data = $title;
            $title = 'Value';
        }
        dprint("$title: " . ($data) . ' (' . strlen($data) . ') ' . bin2hex($data), false);
    }

    private function perform_handshake1()
    {
        $this->authentication_failed = false;
        $this->clear_cookies();
        $url = 'http://' . $this->host . '/app/handshake1';
        $this->localSeed = $this->get_local_seed();
        $result = $this->session_post($url, $this->localSeed);
        if (!$result) {
            return false;
        }
        $this->handle_cookies();

        $remote_seed = substr($result, 0, 16);
        $server_hash = substr($result, 16);
        $this->localAuthHash = $this->generate_auth_hash_alt($this->email, $this->password);
        $local_seed_auth_hash = $this->_sha256($this->localSeed . $remote_seed . $this->localAuthHash);
        if ($local_seed_auth_hash == $server_hash) {
            return array($remote_seed, $this->localAuthHash);
        } else {
            //todo: alternative approaches from https://github.com/petretiandrea/plugp100/blob/b0757fa4cb5408a714d57157e487fb0d565ed246/plugp100/protocol/klap_protocol.py#L160
        }
        return false;
    }

    private function perform_handshake2($remote_seed, $auth_hash)
    {
        $url = 'http://' . $this->host . '/app/handshake2';
        $payload = $this->_sha256($remote_seed . $this->localSeed . $auth_hash);
        $response = $this->session_post($url, $payload);
        return $response;
    }

    public function handshake()
    {
        list($remote_seed, $auth_hash) = $this->perform_handshake1();
        if ($remote_seed && $auth_hash) {
            $response = $this->perform_handshake2($remote_seed, $auth_hash);
            if ($response) {
                $this->KlapSession = new KlapChiper($this->localSeed, $remote_seed, $auth_hash);
                return true;
            } else {
                $this->KlapSession = false;
            }
        }
        return false;
    }

    public function sendRequest($request)
    {
        if (!$this->KlapSession) {
            $this->handshake();
        }
        if (!$this->KlapSession) {
            return false;
        }
        $payload = $this->KlapSession->encrypt($request);
        $url = 'http://' . $this->host . '/app/request?seq=' . $this->KlapSession->seq;
        $response = $this->session_post($url, $payload);
        if ($response!='') {
            $decrypted_response = $this->KlapSession->decrypt($response);
            $data = json_decode($decrypted_response, true);
            if (is_array($data)) {
                return $data;
            } else {
                return false;
            }
        } else {
            return false;
        }

    }

    public function turnOn()
    {
        $request = $this->prepareRequest('set_device_info', array('device_on'=>true));
        $payload = json_encode($request);
        $response =  $this->sendRequest($payload);
        if (is_array($response)) {
            return true;
        } else {
            return false;
        }
    }

    function prepareRequest($method, $params = 0) {
        $request = array('method'=>$method);
        if (is_array($params)) {
            $request['params'] = $params;
        }
        $request['requestTimeMils'] = round(microtime(true) * 1000);
        $request['terminalUUID'] = $this->terminalUUID;
        return $request;
    }

    public function turnOff()
    {
        //$request = $this->prepareRequest('get_device_info');
        $request = $this->prepareRequest('set_device_info', array('device_on'=>false));
        $payload = json_encode($request);
        $response = $this->sendRequest($payload);
        if (is_array($response)) {
            return true;
        } else {
            return false;
        }
    }

}


require DIR_MODULES.'tapo/KlapChiper2.class.php';