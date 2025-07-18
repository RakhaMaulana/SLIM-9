<?php
/**
 * Copyright (C) 2009  Arie Nugraha (dicarve@yahoo.com)
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
 *
 */

/* Item Status Management section */

// key to authenticate
define('INDEX_AUTH', '1');
// key to get full database access
define('DB_ACCESS', 'fa');

// main system configuration
require '../../../sysconfig.inc.php';
// IP based access limitation
require LIB.'ip_based_access.inc.php';
do_checkIP('smc');
do_checkIP('smc-masterfile');
// start the session
require SB.'admin/default/session.inc.php';
require SB.'admin/default/session_check.inc.php';
require SIMBIO.'simbio_GUI/table/simbio_table.inc.php';
require SIMBIO.'simbio_GUI/form_maker/simbio_form_table_AJAX.inc.php';
require SIMBIO.'simbio_GUI/paging/simbio_paging.inc.php';
require SIMBIO.'simbio_DB/datagrid/simbio_dbgrid.inc.php';
require SIMBIO.'simbio_DB/simbio_dbop.inc.php';

// privileges checking
$can_read = utility::havePrivilege('master_file', 'r');
$can_write = utility::havePrivilege('master_file', 'w');

if (!$can_read) {
    die('<div class="errorBox">'.__('You don\'t have enough privileges to access this area!').'</div>');
}

// item status rules
$rules_option[] = array(NO_LOAN_TRANSACTION, __('No Loan Transaction'));
$rules_option[] = array(SKIP_STOCK_TAKE, __('Skipped By Stock Take'));

/* RECORD OPERATION */
if (isset($_POST['saveData'])) {
    $itemStatusID = strip_tags(trim($_POST['itemStatusID']));
    $itemStatusName = strip_tags(trim($_POST['itemStatus']));
    // check form validity
    if (empty($itemStatusID) OR empty($itemStatusName)) {
        utility::jsToastr(__('Item Status'),__('Item Status ID and Name can\'t be empty'),'error');
        exit();
    } else {
        $data['item_status_id'] = $dbs->escape_string($itemStatusID);
        $data['item_status_name'] = $dbs->escape_string($itemStatusName);
        // parsing rules
		/*
        $rules = '';
        if (isset($_POST['rules']) AND !empty($_POST['rules'])) {
            $rules = serialize($_POST['rules']);
        } else {
            $rules = 'literal{NULL}';
        }
		*/
        $data['rules'] = 'literal{NULL}';
        $data['no_loan'] = '0';
        $data['skip_stock_take'] = '0';
        foreach ($_POST['rules']??[] as $rule) {
            if ((integer)$rule === NO_LOAN_TRANSACTION) $data['no_loan'] = '1';
            if ((integer)$rule === SKIP_STOCK_TAKE) $data['skip_stock_take'] = '1';
        }
        $data['input_date'] = date('Y-m-d');
        $data['last_update'] = date('Y-m-d');

        // create sql op object
        $sql_op = new simbio_dbop($dbs);
        if (isset($_POST['updateRecordID'])) {
            /* UPDATE RECORD MODE */
            // remove input date
            unset($data['input_date']);
            // filter update record ID
            $updateRecordID = $dbs->escape_string(trim($_POST['updateRecordID']));
            // update the data
            $update = $sql_op->update('mst_item_status', $data, 'item_status_id=\''.$updateRecordID.'\'');
            if ($update) {
                utility::jsToastr(__('Item Status'), __('New Item Status Data Successfully Updated'), 'success');
                // update item status ID in item table to keep data integrity
                $sql_op->update('item', array('item_status_id' => $data['item_status_id']), 'item_status_id=\''.$updateRecordID.'\'');
                echo '<script type="text/javascript">parent.jQuery(\'#mainContent\').simbioAJAX(parent.jQuery.ajaxHistory[0].url);</script>';
            } else { 
                utility::jsToastr(__('Item Status'),__('Item Status Data FAILED to Updated. Please Contact System Administrator')."\nDEBUG : ".$sql_op->error,'error'); 
            }
            exit();
        } else {
            /* INSERT RECORD MODE */
            // insert the data
            $insert = $sql_op->insert('mst_item_status', $data);
            if ($insert) {                
                utility::jsToastr(__('Item Status'), __('New Item Status Data Successfully Saved'), 'success');
                echo '<script type="text/javascript">parent.jQuery(\'#mainContent\').simbioAJAX(\''.$_SERVER['PHP_SELF'].'\');</script>';
            } else { 
                utility::jsToastr(__('Item Status'), __('Item Status Data FAILED to Save. Please Contact System Administrator')."\n".$sql_op->error, 'error');
            }
            exit();
        }
    }
    exit();
} else if (isset($_POST['itemID']) AND !empty($_POST['itemID']) AND isset($_POST['itemAction'])) {
    if (!($can_read AND $can_write)) {
        die();
    }
    /* DATA DELETION PROCESS */
    $sql_op = new simbio_dbop($dbs);
    $failed_array = array();
    $error_num = 0;
    $still_have_item = array();    
    if (!is_array($_POST['itemID'])) {
        // make an array
        $_POST['itemID'] = array($dbs->escape_string(trim($_POST['itemID'])));
    }
    // loop array
    foreach ($_POST['itemID'] as $itemID) {
        $itemID = $dbs->escape_string(trim($itemID));
        // check if this place data still in use items
        $_sql_status_item_q = sprintf('SELECT mis.item_status_name, COUNT(mis.item_status_id) FROM item AS i
        LEFT JOIN mst_item_status AS mis ON i.item_status_id=mis.item_status_id
        WHERE mis.item_status_id=%d GROUP BY mis.item_status_name', $itemID);
        $status_item_q = $dbs->query($_sql_status_item_q);
        $status_item_d = $status_item_q->fetch_row();
        if ($status_item_d[1] < 1) {
            if (!$sql_op->delete('mst_item_status', "item_status_id='$itemID'")) {
                $error_num++;
            }
        }else{
            $still_have_item[] = sprintf(__('%s still in use %d item ').'<br/>',substr($status_item_d[0], 0, 45),$status_item_d[1]);
            $error_num++;            
        }
    }

    if ($still_have_item) {
        $titles = '';
        foreach ($still_have_item as $title) {
            $titles .= $title . "\n";
        }
        utility::jsToastr( __('Item Status'), __('Below data can not be deleted:') . "\n" . $titles, 'error');
        echo '<script type="text/javascript">parent.$(\'#mainContent\').simbioAJAX(\'' . $_SERVER['PHP_SELF'] . '\', {addData: \'' . $_POST['lastQueryStr'] . '\'});</script>';
        exit();
    }

    // error alerting
    if ($error_num == 0) {
        utility::jsToastr(__('Item Status'), __('All Data Successfully Deleted'), 'success');
        echo '<script type="text/javascript">parent.jQuery(\'#mainContent\').simbioAJAX(\''.$_SERVER['PHP_SELF'].'?'.$_POST['lastQueryStr'].'\');</script>';
    } else {
        utility::jsToastr(__('Item Status'), __('Some or All Data NOT deleted successfully!\nPlease contact system administrator'), 'warning');        
        echo '<script type="text/javascript">parent.jQuery(\'#mainContent\').simbioAJAX(\''.$_SERVER['PHP_SELF'].'?'.$_POST['lastQueryStr'].'\');</script>';
    }
    exit();
}
/* item status update process end */

/* search form */
?>
<div class="menuBox">
<div class="menuBoxInner masterFileIcon">
	<div class="per_title">
	    <h2><?php echo __('Item Status'); ?></h2>
  </div>
	<div class="sub_section">
	  <div class="btn-group">
      <a href="<?php echo MWB; ?>master_file/item_status.php" class="btn btn-default"><?php echo __('Item Status'); ?></a>
		  <a href="<?php echo MWB; ?>master_file/item_status.php?action=detail" class="btn btn-default"><?php echo __('Add New Item Status'); ?></a>
	  </div>
    <form name="search" action="<?php echo MWB; ?>master_file/item_status.php" id="search" method="get" class="form-inline"><?php echo __('Search'); ?> 
    <input type="text" name="keywords" class="form-control col-md-3" />
    <input type="submit" id="doSearch" value="<?php echo __('Search'); ?>" class="s-btn btn btn-default" />
    </form>
	</div>
</div>
</div>
<?php
/* search form end */
/* main content */
if (isset($_POST['detail']) OR (isset($_GET['action']) AND $_GET['action'] == 'detail')) {
    if (!($can_read AND $can_write)) {
        die('<div class="errorBox">'.__('You don\'t have enough privileges to access this area!').'</div>');
    }
    /* RECORD FORM */
    $itemID = trim($dbs->escape_string(isset($_POST['itemID'])?$_POST['itemID']:''));
    $rec_q = $dbs->query("SELECT * FROM mst_item_status WHERE item_status_id='$itemID'");
    $rec_d = $rec_q->fetch_assoc();

    // create new instance
    $form = new simbio_form_table_AJAX('mainForm', $_SERVER['PHP_SELF'].'?'.$_SERVER['QUERY_STRING'], 'post');
    $form->submit_button_attr = 'name="saveData" value="'.__('Save').'" class="s-btn btn btn-default"';

    // form table attributes
    $form->table_attr = 'id="dataList" class="s-table table"';
    $form->table_header_attr = 'class="alterCell font-weight-bold"';
    $form->table_content_attr = 'class="alterCell2"';

    // edit mode flag set
    if ($rec_q->num_rows > 0) {
        $form->edit_mode = true;
        // record ID for delete process
        $form->record_id = $itemID;
        // form record title
        $form->record_title = $rec_d['item_status_name'];
        // submit button attribute
        $form->submit_button_attr = 'name="saveData" value="'.__('Update').'" class="s-btn btn btn-primary"';
    }

    /* Form Element(s) */
    // item status code
    $form->addTextField('text', 'itemStatusID', __('Item Status Code').'*', $rec_d['item_status_id']??'', 'style="width: 20%;" maxlength="3" class="form-control"');
    // item status name
    $form->addTextField('text', 'itemStatus', __('Item Status Name').'*', $rec_d['item_status_name']??'', 'style="width: 60%;" class="form-control"');
    // item status rules
	$rules = array();

	if ($rec_d['no_loan']??false) $rules[] = NO_LOAN_TRANSACTION;

	if ($rec_d['skip_stock_take']??false) $rules[] = SKIP_STOCK_TAKE;

    $form->addCheckbox('rules', __('Rules'), $rules_option, $rules);

    // edit mode messagge
    if ($form->edit_mode) {
        echo '<div class="infoBox">'.__('You are going to edit Item Status data').' : <b>'.$rec_d['item_status_name'].'</b>  <br />'.__('Last Update').' '.$rec_d['last_update'].'</div>'; //mfc
    }
    // print out the form object
    echo $form->printOut();
} else {
    /* ITEM STATUS LIST */
    // table spec
    $table_spec = 'mst_item_status AS ist';

    // create datagrid
    $datagrid = new simbio_datagrid();
    if ($can_read AND $can_write) {
        $datagrid->setSQLColumn('ist.item_status_id',
            'ist.item_status_id AS \''.__('Item Status Code').'\'',
            'ist.item_status_name AS \''.__('Item Status Name').'\'',
            'ist.last_update AS \''.__('Last Update').'\'');
    } else {
        $datagrid->setSQLColumn('ist.item_status_id AS \''.__('Item Status Code').'\'',
            'ist.item_status_name AS \''.__('Item Status Name').'\'',
            'ist.last_update AS \''.__('Last Update').'\'');
    }
    $datagrid->setSQLorder('item_status_name ASC');

    // change the record order
    if (isset($_GET['fld']) AND isset($_GET['dir'])) {
        $datagrid->setSQLorder("'".urldecode($_GET['fld'])."' ".$dbs->escape_string($_GET['dir']));
    }

    // is there any search
    if (isset($_GET['keywords']) AND $_GET['keywords']) {
       $keywords = utility::filterData('keywords', 'get', true, true, true);
       $datagrid->setSQLCriteria("ist.item_status_name LIKE '%$keywords%'");
    }

    // set table and table header attributes
    $datagrid->table_attr = 'id="dataList" class="s-table table"';
    $datagrid->table_header_attr = 'class="dataListHeader" style="font-weight: bold;"';
    // set delete proccess URL
    $datagrid->chbox_form_URL = $_SERVER['PHP_SELF'];

    // put the result into variables
    $datagrid_result = $datagrid->createDataGrid($dbs, $table_spec, 20, ($can_read AND $can_write));
    if (isset($_GET['keywords']) AND $_GET['keywords']) {
        $msg = str_replace('{result->num_rows}', $datagrid->num_rows, __('Found <strong>{result->num_rows}</strong> from your keywords')); //mfc
        echo '<div class="infoBox">'.$msg.' : "'.htmlspecialchars($_GET['keywords']).'"</div>';
    }

    echo $datagrid_result;
}
/* main content end */
