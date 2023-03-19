<?php
/*
* @version 0.1 (wizard)
*/
global $session;
if ($this->owner->name == 'panel') {
    $out['CONTROLPANEL'] = 1;
}

$go_linked_object = gr('go_linked_object');
$go_linked_property = gr('go_linked_property');
if ($go_linked_object && $go_linked_property) {
    $tmp = SQLSelectOne("SELECT ID, DEVICE_ID FROM tapoproperties WHERE LINKED_OBJECT = '" . DBSafe($go_linked_object) . "' AND LINKED_PROPERTY='" . DBSafe($go_linked_property) . "'");
    if ($tmp['ID']) {
        $this->redirect("?id=" . $tmp['ID'] . "&view_mode=edit_tapodevices&id=" . $tmp['DEVICE_ID'] . "&tab=data");
    }
}

$qry = "1";
// search filters
// QUERY READY
global $save_qry;
if ($save_qry) {
    $qry = $session->data['tapodevices_qry'];
} else {
    $session->data['tapodevices_qry'] = $qry;
}
if (!$qry) $qry = "1";
$sortby_tapodevices = "ID DESC";
$out['SORTBY'] = $sortby_tapodevices;
// SEARCH RESULTS
$res = SQLSelect("SELECT * FROM tapodevices WHERE $qry ORDER BY " . $sortby_tapodevices);
if ($res[0]['ID']) {
    //paging($res, 100, $out); // search result paging
    $total = count($res);
    for ($i = 0; $i < $total; $i++) {
        $commands = SQLSelect("SELECT * FROM tapoproperties WHERE DEVICE_ID=" . $res[$i]['ID']);
        $total_c = count($commands);
        for ($ic = 0; $ic < $total_c; $ic++) {
            if ($commands[$ic]['LINKED_OBJECT'] != '') {
                $device = SQLSelectOne("SELECT ID, TITLE FROM devices WHERE LINKED_OBJECT='" . DBSafe($commands[$ic]['LINKED_OBJECT']) . "'");
                if ($device['TITLE']) {
                    $res[$i]['COMMANDS'] .= ' - <a href="' . ROOTHTML . 'panel/devices/' . $device['ID'] . '.html" target=_blank>' . $device['TITLE'] . '</a>';
                } else {
                    $res[$i]['COMMANDS'] .= ' (' . $commands[$ic]['LINKED_OBJECT'];
                    if ($commands[$ic]['LINKED_PROPERTY'] != '') {
                        $res[$i]['COMMANDS'] .= '.' . $commands[$ic]['LINKED_PROPERTY'];
                    } elseif ($commands[$ic]['LINKED_METHOD'] != '') {
                        $res[$i]['COMMANDS'] .= '.' . $commands[$ic]['LINKED_METHOD'];
                    }
                    $res[$i]['COMMANDS'] .= ')';
                }
            }
        }
    }
    $out['RESULT'] = $res;
}
