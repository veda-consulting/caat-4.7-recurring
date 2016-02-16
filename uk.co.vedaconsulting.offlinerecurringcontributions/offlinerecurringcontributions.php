<?php

require_once 'offlinerecurringcontributions.civix.php';

/**
 * Implements hook_civicrm_config().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_config
 */
function offlinerecurringcontributions_civicrm_config(&$config) {
  _offlinerecurringcontributions_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_xmlMenu().
 *
 * @param $files array(string)
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_xmlMenu
 */
function offlinerecurringcontributions_civicrm_xmlMenu(&$files) {
  _offlinerecurringcontributions_civix_civicrm_xmlMenu($files);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_install
 */
function offlinerecurringcontributions_civicrm_install() {
  $extensionDir = dirname( __FILE__ ) . DIRECTORY_SEPARATOR;
  $sqlName = $extensionDir.'sql'.DIRECTORY_SEPARATOR.'update.sql';
  CRM_Utils_File::sourceSQLFile(CIVICRM_DSN, $sqlName );
  _offlinerecurringcontributions_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_uninstall().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_uninstall
 */
function offlinerecurringcontributions_civicrm_uninstall() {
  _offlinerecurringcontributions_civix_civicrm_uninstall();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_enable
 */
function offlinerecurringcontributions_civicrm_enable() {
  // looks like someone finally wrote an api ..
  civicrm_api('job', 'create', array(
       'version'       => 3,
       'name'          => ts('Process Offline Recurring Payments'),
       'description'   => ts('Processes any offline recurring payments that are due'),
       'run_frequency' => 'Daily',
       'api_entity'    => 'job',
       'api_action'    => 'process_offline_recurring_payments',
       'is_active'     => 0
   ));
  _offlinerecurringcontributions_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_disable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_disable
 */
function offlinerecurringcontributions_civicrm_disable() {
  CRM_Core_DAO::executeQuery("
        DELETE FROM civicrm_job WHERE api_action = 'process_offline_recurring_payments'
  ");
  _offlinerecurringcontributions_civix_civicrm_disable();
}

/**
 * Implements hook_civicrm_upgrade().
 *
 * @param $op string, the type of operation being performed; 'check' or 'enqueue'
 * @param $queue CRM_Queue_Queue, (for 'enqueue') the modifiable list of pending up upgrade tasks
 *
 * @return mixed
 *   Based on op. for 'check', returns array(boolean) (TRUE if upgrades are pending)
 *                for 'enqueue', returns void
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_upgrade
 */
function offlinerecurringcontributions_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _offlinerecurringcontributions_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implements hook_civicrm_managed().
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_managed
 */
function offlinerecurringcontributions_civicrm_managed(&$entities) {
  _offlinerecurringcontributions_civix_civicrm_managed($entities);
}

/**
 * Implements hook_civicrm_caseTypes().
 *
 * Generate a list of case-types
 *
 * Note: This hook only runs in CiviCRM 4.4+.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_caseTypes
 */
function offlinerecurringcontributions_civicrm_caseTypes(&$caseTypes) {
  _offlinerecurringcontributions_civix_civicrm_caseTypes($caseTypes);
}

/**
 * Implements hook_civicrm_angularModules().
 *
 * Generate a list of Angular modules.
 *
 * Note: This hook only runs in CiviCRM 4.5+. It may
 * use features only available in v4.6+.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_caseTypes
 */
function offlinerecurringcontributions_civicrm_angularModules(&$angularModules) {
_offlinerecurringcontributions_civix_civicrm_angularModules($angularModules);
}

/**
 * Implements hook_civicrm_alterSettingsFolders().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_alterSettingsFolders
 */
function offlinerecurringcontributions_civicrm_alterSettingsFolders(&$metaDataFolders = NULL) {
  _offlinerecurringcontributions_civix_civicrm_alterSettingsFolders($metaDataFolders);
}

// cron job converted from standalone cron script to job api call, andyw@circle
function civicrm_api3_job_process_offline_recurring_payments($params) {
  
  $paymentProcessorType   = CRM_Core_PseudoConstant::paymentProcessorType(false, null, 'name');
  $paymentProcessorTypeId = CRM_Utils_Array::key('Manual', $paymentProcessorType);
  if(empty($paymentProcessorTypeId)) {
    return civicrm_api3_create_error(
      ts("There is no 'Manual' payment processor type") 
    );
  }
  $config = &CRM_Core_Config::singleton();
  $debug  = false;

  $dtCurrentDay      = date("Ymd", mktime(0, 0, 0, date("m") , date("d") , date("Y")));
  $dtCurrentDayStart = $dtCurrentDay."000000"; 
  $dtCurrentDayEnd   = $dtCurrentDay."235959"; 

  // Select the recurring payment, where current date is equal to next scheduled date
  $sql = "
      SELECT ccr.* FROM civicrm_contribution_recur ccr
      INNER JOIN civicrm_payment_processor cpp ON (cpp.id = ccr.payment_processor_id AND cpp.is_test = 0)
      INNER JOIN civicrm_payment_processor_type cppt ON cppt.id = cpp.payment_processor_type_id
      WHERE cppt.name = 'Manual'
      AND (ccr.end_date IS NULL OR ccr.end_date > NOW())
      AND ccr.next_sched_contribution_date >= %1 
      AND ccr.next_sched_contribution_date <= %2
  ";

  $dao = CRM_Core_DAO::executeQuery($sql, array(
        1 => array($dtCurrentDayStart, 'String'),
        2 => array($dtCurrentDayEnd, 'String')
     )
  );

  $counter = 0;
  $errors  = 0;
  $output  = array();

  while($dao->fetch()) {
    $hash                       = md5(uniqid(rand(), true)); 
    require_once 'api/api.php';
    $result = civicrm_api('contribution', 'create',
        array(
            'version'                => 3,
            'contact_id'             => $dao->contact_id,
            'receive_date'           => date("YmdHis"),
            'total_amount'           => $dao->amount,
            'payment_instrument_id'  => $dao->payment_instrument_id,
            'trxn_id'                => $hash,
            'invoice_id'             => $hash,
            'source'                 => "Offline Recurring Contribution",
            'contribution_status_id' => 2,
            'contribution_type_id'   => 1,
            'contribution_recur_id'  => $dao->id,
            //'contribution_page_id'   => $entity_id
        )
    );
    if ($result['is_error']) {
        $output[] = $result['error_message'];
        ++$errors;
        ++$counter;
        continue;
    } else {
        $contribution = reset($result['values']);
        $contribution_id = $contribution['id'];
        $output[] = ts('Created contribution record for contact id %1', array(1 => $dao->contact_id)); 
    }

    //$mem_end_date = $member_dao->end_date;
    $temp_date = strtotime($dao->next_sched_contribution_date);

    $next_collectionDate = strtotime ("+$dao->frequency_interval $dao->frequency_unit", $temp_date);
    $next_collectionDate = date('YmdHis', $next_collectionDate);

    $sql = "
        UPDATE civicrm_contribution_recur 
           SET next_sched_contribution_date = %1 
         WHERE id = %2
    ";
    CRM_Core_DAO::executeQuery($sql, array(
           1 => array($next_collectionDate, 'String'),
           2 => array($dao->id, 'Integer')
       )
    );
    
    $result = civicrm_api('activity', 'create',
        array(
            'version'             => 3,
            'activity_type_id'    => 6,
            'source_record_id'    => $contribution_id,
            'source_contact_id'   => $dao->contact_id,
            'assignee_contact_id' => $dao->contact_id,
            'subject'             => "Offline Recurring Contribution - " . $dao->amount,
            'status_id'           => 2,
            'activity_date_time'  => date("YmdHis"),            
        )
    );
    if ($result['is_error']) {
        $output[] = ts(
            'An error occurred while creating activity record for contact id %1: %2',
            array(
                1 => $dao->contact_id,
                2 => $result['error_message']
            )
        );
        ++$errors;
    } else {
      $output[] = ts('Created activity record for contact id %1', array(1 => $dao->contact_id)); 
    }
    ++$counter;
  }
  // If errors ..
  if ($errors)
      return civicrm_api3_create_error(
          ts("Completed, but with %1 errors. %2 records processed.", 
              array(
                  1 => $errors,
                  2 => $counter
              )
          ) . "<br />" . implode("<br />", $output)
      );

  // If no errors and records processed ..
  if ($counter)
      return civicrm_api3_create_success(
          ts(
              '%1 contribution record(s) were processed.', 
              array(
                  1 => $counter
              )
          ) . "<br />" . implode("<br />", $output)
      );

  // No records processed
  return civicrm_api3_create_success(ts('No contribution records were processed.'));
}   

