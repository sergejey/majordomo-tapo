<?php
/**
 * Tapo
 * @package project
 * @author Wizard <sergejey@gmail.com>
 * @copyright http://majordomo.smartliving.ru/ (c)
 * @version 0.1 (wizard, 23:12:52 [Dec 29, 2021])
 */
//
//
class tapo extends module
{
    /**
     * tapo
     *
     * Module class constructor
     *
     * @access private
     */
    function __construct()
    {
        $this->name = "tapo";
        $this->title = "Tapo";
        $this->module_category = "<#LANG_SECTION_DEVICES#>";
        $this->checkInstalled();
    }

    /**
     * saveParams
     *
     * Saving module parameters
     *
     * @access public
     */
    function saveParams($data = 1)
    {
        $p = array();
        if (isset($this->id)) {
            $p["id"] = $this->id;
        }
        if (isset($this->view_mode)) {
            $p["view_mode"] = $this->view_mode;
        }
        if (isset($this->edit_mode)) {
            $p["edit_mode"] = $this->edit_mode;
        }
        if (isset($this->data_source)) {
            $p["data_source"] = $this->data_source;
        }
        if (isset($this->tab)) {
            $p["tab"] = $this->tab;
        }
        return parent::saveParams($p);
    }

    /**
     * getParams
     *
     * Getting module parameters from query string
     *
     * @access public
     */
    function getParams()
    {
        global $id;
        global $mode;
        global $view_mode;
        global $edit_mode;
        global $data_source;
        global $tab;
        if (isset($id)) {
            $this->id = $id;
        }
        if (isset($mode)) {
            $this->mode = $mode;
        }
        if (isset($view_mode)) {
            $this->view_mode = $view_mode;
        }
        if (isset($edit_mode)) {
            $this->edit_mode = $edit_mode;
        }
        if (isset($data_source)) {
            $this->data_source = $data_source;
        }
        if (isset($tab)) {
            $this->tab = $tab;
        }
    }

    /**
     * Run
     *
     * Description
     *
     * @access public
     */
    function run()
    {
        global $session;
        $out = array();
        if ($this->action == 'admin') {
            $this->admin($out);
        } else {
            $this->usual($out);
        }
        if (isset($this->owner->action)) {
            $out['PARENT_ACTION'] = $this->owner->action;
        }
        if (isset($this->owner->name)) {
            $out['PARENT_NAME'] = $this->owner->name;
        }
        $out['VIEW_MODE'] = $this->view_mode;
        $out['EDIT_MODE'] = $this->edit_mode;
        $out['MODE'] = $this->mode;
        $out['ACTION'] = $this->action;
        $out['DATA_SOURCE'] = $this->data_source;
        $out['TAB'] = $this->tab;
        $this->data = $out;
        $p = new parser(DIR_TEMPLATES . $this->name . "/" . $this->name . ".html", $this->data, $this);
        $this->result = $p->result;
    }

    /**
     * BackEnd
     *
     * Module backend
     *
     * @access public
     */
    function admin(&$out)
    {

        if (gr('ok_msg')) {
            $out['OK_MSG'] = gr('ok_msg');
        }

        if (gr('err_msg')) {
            $out['ERR_MSG'] = gr('err_msg');
        }

        $this->getConfig();
        $out['API_USERNAME'] = $this->config['API_USERNAME'];
        $out['API_PASSWORD'] = $this->config['API_PASSWORD'];

        if ($this->view_mode == 'update_settings') {
            $this->config['API_USERNAME'] = gr('api_username');
            $this->config['API_PASSWORD'] = gr('api_password');
            $this->saveConfig();
            $this->refreshDevices();
            $this->redirect("?ok_msg=OK");
        }

        if ($this->mode == 'refresh') {
            $this->refreshDevices();
            $this->redirect("?ok_msg=OK");
        }

        if (isset($this->data_source) && !$_GET['data_source'] && !$_POST['data_source']) {
            $out['SET_DATASOURCE'] = 1;
        }
        if ($this->data_source == 'tapodevices' || $this->data_source == '') {
            if ($this->view_mode == '' || $this->view_mode == 'search_tapodevices') {


                $this->search_tapodevices($out);
            }
            if ($this->view_mode == 'edit_tapodevices') {
                $this->edit_tapodevices($out, $this->id);
            }
            if ($this->view_mode == 'delete_tapodevices') {
                $this->delete_tapodevices($this->id);
                $this->redirect("?data_source=tapodevices");
            }
        }
        if (isset($this->data_source) && !$_GET['data_source'] && !$_POST['data_source']) {
            $out['SET_DATASOURCE'] = 1;
        }
        if ($this->data_source == 'tapoproperties') {
            if ($this->view_mode == '' || $this->view_mode == 'search_tapoproperties') {
                $this->search_tapoproperties($out);
            }
            if ($this->view_mode == 'edit_tapoproperties') {
                $this->edit_tapoproperties($out, $this->id);
            }
        }
    }

    function guidv4($data = null)
    {
        // Generate 16 bytes (128 bits) of random data or use the data passed into the function.
        $data = $data ?? random_bytes(16);
        assert(strlen($data) == 16);

        // Set version to 0100
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        // Set bits 6-7 to 10
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        // Output the 36 character UUID.
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }


    function refreshDevices()
    {

        $this->getConfig();
        $url = 'https://eu-wap.tplinkcloud.com/';

        $uuid = $this->guidv4();

        $payload = array(
            'method' => 'login',
            'params' => array(
                'appType' => 'Tapo_Ios',
                'cloudUserName' => $this->config['API_USERNAME'],
                'cloudPassword' => $this->config['API_PASSWORD'],
                'terminalUUID' => $uuid// "0A950402-7224-46EB-A450-7362CDB902A2"
            )
        );
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $tmpfname = $this->cookieFilename;
        curl_setopt($ch, CURLOPT_COOKIEJAR, $tmpfname);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $tmpfname);
        $result = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($result, true);

        if (isset($data['error_code']) && !$data['error_code']) {
            $token = $data['result']['token'];
            $url = 'https://eu-wap.tplinkcloud.com/?token=' . $token;

            $payload = array(
                'method' => 'getDeviceList'
            );
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $tmpfname = $this->cookieFilename;
            curl_setopt($ch, CURLOPT_COOKIEJAR, $tmpfname);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $tmpfname);
            $result = curl_exec($ch);
            curl_close($ch);

            $data = json_decode($result, true);
            if (isset($data['error_code']) && !$data['error_code']) {
                $devices = $data['result']['deviceList'];
                $total = count($devices);
                for ($i = 0; $i < $total; $i++) {
                    $did = $devices[$i]['deviceId'];
                    if (!$did) continue;
                    $device_rec = SQLSelectOne("SELECT ID FROM tapodevices WHERE DID='" . $did . "'");
                    if (!$device_rec['ID']) {
                        $device_rec['DID'] = $did;
                        $device_rec['TITLE'] = $did;
                        $device_rec['MODEL'] = $devices[$i]['deviceModel'];
                        $device_rec['VERSION_HW'] = $devices[$i]['deviceHwVer'];
                        $device_rec['VERSION_FW'] = $devices[$i]['fwVer'];
                        $device_rec['TYPE'] = $devices[$i]['deviceType'];
                        SQLInsert('tapodevices', $device_rec);
                    } else {
                        $device_rec['VERSION_HW'] = $devices[$i]['deviceHwVer'];
                        $device_rec['VERSION_FW'] = $devices[$i]['fwVer'];
                        SQLUpdate('tapodevices', $device_rec);
                        $this->refreshDevice($device_rec['ID']);
                    }
                }
            }


        }

    }

    /**
     * FrontEnd
     *
     * Module frontend
     *
     * @access public
     */
    function usual(&$out)
    {
        $this->admin($out);
    }

    /**
     * tapodevices search
     *
     * @access public
     */
    function search_tapodevices(&$out)
    {
        require(dirname(__FILE__) . '/tapodevices_search.inc.php');
    }

    /**
     * tapodevices edit/add
     *
     * @access public
     */
    function edit_tapodevices(&$out, $id)
    {
        require(dirname(__FILE__) . '/tapodevices_edit.inc.php');
    }

    /**
     * tapodevices delete record
     *
     * @access public
     */
    function delete_tapodevices($id)
    {
        $rec = SQLSelectOne("SELECT * FROM tapodevices WHERE ID='$id'");
        // some action for related tables
        SQLExec("DELETE FROM tapoproperties WHERE DEVICE_ID='" . $rec['ID'] . "'");
        SQLExec("DELETE FROM tapodevices WHERE ID='" . $rec['ID'] . "'");
    }

    /**
     * tapoproperties search
     *
     * @access public
     */
    function search_tapoproperties(&$out)
    {
        require(dirname(__FILE__) . '/tapoproperties_search.inc.php');
    }

    /**
     * tapoproperties edit/add
     *
     * @access public
     */
    function edit_tapoproperties(&$out, $id)
    {
        require(dirname(__FILE__) . '/tapoproperties_edit.inc.php');
    }

    function updateProperty($device_id, $title, $value = '')
    {
        $property = SQLSelectOne("SELECT * FROM tapoproperties WHERE DEVICE_ID=" . $device_id . " AND TITLE LIKE '" . DBSafe($title) . "'");
        $property['DEVICE_ID'] = $device_id;
        $property['TITLE'] = $title;
        $old_value = $property['VALUE'];
        $property['VALUE'] = $value;

        if ($old_value != $value || !$property['ID']) {
            $property['UPDATED'] = date('Y-m-d H:i:s');
        }

        if ($property['ID']) {
            SQLUpdate('tapoproperties', $property);
        } else {
            $property['ID'] = SQLInsert('tapoproperties', $property);
        }

        if ($old_value != $value) {
            if ($property['LINKED_OBJECT'] != '' && $property['LINKED_PROPERTY']) {
                setGlobal($property['LINKED_OBJECT'] . '.' . $property['LINKED_PROPERTY'], $value, array('tapo' => '0'));
            }
        }

    }

    function refreshDevice($device_id)
    {
        $this->getConfig();

        $device = SQLSelectOne("SELECT * FROM tapodevices WHERE ID=" . (int)$device_id);
        if (!$device['ID']) return false;
        if (!$device['IP']) return false;

        include_once DIR_MODULES . 'tapo/p100.class.php';
        $dev = new p100($device['IP'], $this->config['API_USERNAME'], $this->config['API_PASSWORD']);
        if ($dev->handshake()) {
            if ($dev->login()) {
                $data = $dev->getDeviceInfo();
                if (is_array($data)) {
                    $device['TYPE'] = $data['type'];
                    $device['DID'] = $data['device_id'];
                    $device['MODEL'] = $data['model'];
                    SQLUpdate('tapodevices', $device);
                    $this->updateProperty($device['ID'], 'status', (int)$data['device_on']);
                    return true;
                }
            }
        }
        return false;
    }

    function turnOnDevice($device_id)
    {
        $this->getConfig();

        $device = SQLSelectOne("SELECT * FROM tapodevices WHERE ID=" . (int)$device_id);
        if (!$device['ID']) return false;

        include_once DIR_MODULES . 'tapo/p100.class.php';
        $dev = new p100($device['IP'], $this->config['API_USERNAME'], $this->config['API_PASSWORD']);
        if ($dev->handshake()) {
            if ($dev->login()) {
                if ($dev->turnOn()) {
                    $this->updateProperty($device_id, 'status', 1);
                    return true;
                }
            }
        } else {
            include_once DIR_MODULES . 'tapo/klapProtocol.class.php';
            $dev = new TPLinkKlap($device['IP'], $this->config['API_USERNAME'], $this->config['API_PASSWORD']);
            if ($dev->handshake()) {
                if ($dev->turnOn()) {
                    $this->updateProperty($device_id, 'status', 1);
                    return true;
                }
            }
        }
        return false;

    }

    function turnOffDevice($device_id)
    {

        $this->getConfig();

        $device = SQLSelectOne("SELECT * FROM tapodevices WHERE ID=" . (int)$device_id);
        if (!$device['ID']) return false;

        include_once DIR_MODULES . 'tapo/p100.class.php';
        $dev = new p100($device['IP'], $this->config['API_USERNAME'], $this->config['API_PASSWORD']);
        if ($dev->handshake()) {
            if ($dev->login()) {
                if ($dev->turnOff()) {
                    $this->updateProperty($device_id, 'status', 0);
                    return true;
                }
            }
        } else {
            include_once DIR_MODULES . 'tapo/klapProtocol.class.php';
            $dev = new TPLinkKlap($device['IP'], $this->config['API_USERNAME'], $this->config['API_PASSWORD']);
            if ($dev->handshake()) {
                if ($dev->turnOff()) {
                    $this->updateProperty($device_id, 'status', 0);
                    return true;
                }
            }
        }
        return false;
    }

    function propertySetHandle($object, $property, $value)
    {
        $table = 'tapoproperties';
        $properties = SQLSelect("SELECT tapoproperties.* FROM tapoproperties WHERE LINKED_OBJECT LIKE '" . DBSafe($object) . "' AND LINKED_PROPERTY LIKE '" . DBSafe($property) . "'");
        $total = count($properties);
        if ($total) {
            for ($i = 0; $i < $total; $i++) {

                $properties[$i]['VALUE'] = $value;
                $properties[$i]['UPDATED'] = date('Y-m-d H:i:s');
                SQLUpdate('tapoproperties', $properties[$i]);

                if ($property == 'status') {
                    if ($value) {
                        $this->turnOnDevice($properties[$i]['DEVICE_ID']);
                    } else {
                        $this->turnOffDevice($properties[$i]['DEVICE_ID']);
                    }
                }
            }
        }
    }

    /**
     * Install
     *
     * Module installation routine
     *
     * @access private
     */
    function install($data = '')
    {
        parent::install();
    }

    /**
     * Uninstall
     *
     * Module uninstall routine
     *
     * @access public
     */
    function uninstall()
    {
        SQLExec('DROP TABLE IF EXISTS tapodevices');
        SQLExec('DROP TABLE IF EXISTS tapoproperties');
        parent::uninstall();
    }

    /**
     * dbInstall
     *
     * Database installation routine
     *
     * @access private
     */
    function dbInstall($data)
    {
        /*
        tapodevices -
        tapoproperties -
        */
        $data = <<<EOD
 tapodevices: ID int(10) unsigned NOT NULL auto_increment
 tapodevices: TITLE varchar(100) NOT NULL DEFAULT ''
 tapodevices: IP varchar(255) NOT NULL DEFAULT ''
 tapodevices: DID varchar(255) NOT NULL DEFAULT ''
 tapodevices: TYPE varchar(255) NOT NULL DEFAULT ''
 tapodevices: MODEL varchar(255) NOT NULL DEFAULT ''
 tapodevices: VERSION_HW varchar(255) NOT NULL DEFAULT ''
 tapodevices: VERSION_FW varchar(255) NOT NULL DEFAULT ''
 
 tapoproperties: ID int(10) unsigned NOT NULL auto_increment
 tapoproperties: TITLE varchar(100) NOT NULL DEFAULT ''
 tapoproperties: VALUE varchar(255) NOT NULL DEFAULT ''
 tapoproperties: DEVICE_ID int(10) NOT NULL DEFAULT '0'
 tapoproperties: LINKED_OBJECT varchar(100) NOT NULL DEFAULT ''
 tapoproperties: LINKED_PROPERTY varchar(100) NOT NULL DEFAULT ''
 tapoproperties: UPDATED datetime
 
EOD;
        parent::dbInstall($data);
    }
// --------------------------------------------------------------------
}
/*
*
* TW9kdWxlIGNyZWF0ZWQgRGVjIDI5LCAyMDIxIHVzaW5nIFNlcmdlIEouIHdpemFyZCAoQWN0aXZlVW5pdCBJbmMgd3d3LmFjdGl2ZXVuaXQuY29tKQ==
*
*/
