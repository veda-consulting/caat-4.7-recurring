<?php

/**
 * A custom contact search
 */
class CRM_Recurringcontributioncustomsearches_Form_Search_LateRecurringContributions extends CRM_Contact_Form_Search_Custom_Base implements CRM_Contact_Form_Search_Interface {

  protected $_formValues;
  public $_permissionedComponent;

  /**
   * @param $formValues
   */
  public function __construct(&$formValues) {
    $this->_formValues = $formValues;
    $this->tableName = 'civicrm_temp_custom_late_recurring_search';
    if(CRM_Core_DAO::checkTableExists($this->tableName)) {
      $query = "DROP TABLE {$this->tableName}";
      CRM_Core_DAO::executeQuery($query);
    } 

    $creatSql =
      "CREATE TABLE {$this->tableName}
       SELECT 
       r.id,
       r.contact_id,
       r.amount, r.frequency_unit,
       r.frequency_interval, 
       r.start_date,
       r.cancel_date,
       r.end_date, 
       r.next_sched_contribution_date,
       c.receive_date ,
       c.id as contribution_id,
       r.start_date as expected_date
       FROM civicrm_contribution_recur r 
       LEFT JOIN civicrm_contribution c ON c.receive_date = (SELECT MAX(tmp.receive_date) FROM civicrm_contribution tmp WHERE r.id = tmp.contribution_recur_id AND tmp.is_test = 0 AND (tmp.contribution_status_id = 1 OR tmp.contribution_status_id = 8 ))
       WHERE r.cancel_date IS NULL GROUP BY r.id";

    CRM_Core_DAO::executeQuery($creatSql);
    $selectQuery = "SELECT receive_date, id, start_date, frequency_unit, frequency_interval, next_sched_contribution_date, expected_date, end_date FROM civicrm_temp_custom_late_recurring_search ";
    $dao = CRM_Core_DAO::executeQuery($selectQuery);
    while($dao->fetch()) {
      // month frquency
      if (!empty($dao->receive_date) && $dao->frequency_unit == 'month') {
        // For example, if the RC's start date is 2015-02-01 and the RC is monthly and the most recent payment was made on 2015-07-31, 
        // then the expected date of payment will be 2015-08-01, 
        // which is calculated by taking the start date and adding the lowest number of months 
        // (because it's a monthly RC) to get a date after the most recent payment.
        //To fix this, please can the expected date of the next payment be calculated as the RC's start date plus the lowest multiple of the RC's interval that is after the most recent payment? 
        //(Unless 'next_sched_contribution_date' is set to a date after the last payment was received, in which case that should be used.)
        $startDate        = CRM_Utils_Date::processDate($dao->start_date);
        $lastReceiveDate  = CRM_Utils_Date::processDate($dao->receive_date);
      	$ModifiedDate[0] = date('y', strtotime(date("Y-m-d", strtotime($lastReceiveDate)) . " +".$dao->frequency_interval." month"));
      	$ModifiedDate[1] = date("m",strtotime(date("Y-m-d", strtotime($lastReceiveDate)) . " +".$dao->frequency_interval." month"));
      	$ModifiedDate[2] = date("d", strtotime($startDate));
      	$newDate	 = implode('-',$ModifiedDate);
        // Calculate expected date from last payment
      	$expectedDate	 = date("Y-m-d H:i:s", strtotime($newDate)); 
        $nextschduleDate = date("Y-m-d H:i:s", strtotime($dao->next_sched_contribution_date));
        // If next scheduled date is great than last payment date, then change expected date to next scheduled date
        if (!empty($nextschduleDate) && (date("Y-m-d H:i:s", strtotime($lastReceiveDate)) <= $nextschduleDate)) {
          $expectedDate = $nextschduleDate;
        }
        $recurringId = $dao->id;
        $updateQuery = "UPDATE `civicrm_temp_custom_late_recurring_search` SET expected_date = '{$expectedDate}' WHERE id = {$recurringId}";      
        CRM_Core_DAO::executeQuery($updateQuery);
		

        //http://support.vedaconsulting.co.uk/issues/365
        //The spec says that the search "will not look for missed payments after the recurring contribution's end date, if set."
        if (!empty($dao->end_date) && ($expectedDate > $dao->end_date)) {
          $recurringId = $dao->id;
          $deleteQuery = "DELETE FROM `civicrm_temp_custom_late_recurring_search` WHERE id = {$recurringId}";
          CRM_Core_DAO::executeQuery($deleteQuery);
        }
      	//http://support.vedaconsulting.co.uk/issues/700
      	//please can you allow for a margin of error: An expected payment should count as being paid if it was received in the seven days before the expected date.
				$nofoMonths = 0;
				//if (!empty($this->_formValues['start_date'])) {
					//$beforeDate = CRM_Utils_Date::processDate($this->_formValues['start_date']);
					//$nofoMonths = (int)abs((strtotime($lastReceiveDate) - strtotime($expectedDate))/(60*60*24*30));
				//}
				
				if ((date("Y-m-d", strtotime($lastReceiveDate)) <= date("Y-m-d", strtotime($expectedDate))) &&
							(date("Y-m-d", strtotime($lastReceiveDate)) >= date("Y-m-d", strtotime ( '-7 day' . $expectedDate )))
							) {
							$newexpectedDate  = CRM_Utils_Date::processDate($expectedDate);
							$ModifiedDate = array();
							$ModifiedDate[0] = date('y', strtotime(date("Y-m-d", strtotime($newexpectedDate)) . " +".$dao->frequency_interval." month"));
							$ModifiedDate[1] = date("m",strtotime(date("Y-m-d", strtotime($newexpectedDate)) . " +".$dao->frequency_interval." month"));
							$ModifiedDate[2] = date("d", strtotime($newexpectedDate));
							$newExpectedDate	 = implode('-',$ModifiedDate);
							// Calculate expected date from last payment
							$updatedExpectedDate	 = date("Y-m-d H:i:s", strtotime($newExpectedDate)); 
							
							$recurringId = $dao->id;
							$updateQuery = "UPDATE `civicrm_temp_custom_late_recurring_search` SET expected_date = '{$updatedExpectedDate}' WHERE id = {$recurringId}";      
							CRM_Core_DAO::executeQuery($updateQuery);
				}				
				
				/*if ($nofoMonths > 0) {
					
					if (!empty($dao->next_sched_contribution_date)) {
						$newexpectedDate  = CRM_Utils_Date::processDate($expectedDate);
						$ModifiedDate = array();
						$ModifiedDate[0] = date('y', strtotime(date("Y-m-d", strtotime($newexpectedDate)) . " +".$dao->frequency_interval." month"));
						$ModifiedDate[1] = date("m",strtotime(date("Y-m-d", strtotime($newexpectedDate)) . " +".$dao->frequency_interval." month"));
						$ModifiedDate[2] = date("d", strtotime($newexpectedDate));
						$newExpectedDate	 = implode('-',$ModifiedDate);
						// Calculate expected date from last payment
						$updatedExpectedDate	 = date("Y-m-d H:i:s", strtotime($newExpectedDate)); 
						
						$recurringId = $dao->id;
						$updateQuery = "UPDATE `civicrm_temp_custom_late_recurring_search` SET expected_date = '{$updatedExpectedDate}' WHERE id = {$recurringId}";      
						CRM_Core_DAO::executeQuery($updateQuery);
					}
					
				} else {
					
					if ((date("Y-m-d", strtotime($lastReceiveDate)) <= date("Y-m-d", strtotime($expectedDate))) &&
							(date("Y-m-d", strtotime($lastReceiveDate)) >= date("Y-m-d", strtotime ( '-7 day' . $expectedDate )))
							) {
						$recurringId = $dao->id;
						$deleteQuery = "DELETE FROM `civicrm_temp_custom_late_recurring_search` WHERE id = {$recurringId}";
						CRM_Core_DAO::executeQuery($deleteQuery);
					}
				
				}*/
				
      }
	
      // year frequency
      if (!empty($dao->receive_date) && $dao->frequency_unit == 'year') {
        $lastReceiveDate  = CRM_Utils_Date::processDate($dao->receive_date);
        $startDate        = CRM_Utils_Date::processDate($dao->start_date);
      	//$ModifiedDate[0] = date('y', strtotime(date('Ymd')));
        $ModifiedDate[0] = date("y",strtotime(date("Y-m-d", strtotime($lastReceiveDate)) . " +".$dao->frequency_interval." year"));
      	$ModifiedDate[1] = date('m', strtotime($startDate));
      	$ModifiedDate[2] = date("d", strtotime($startDate));
      	$newDate	 = implode('-',$ModifiedDate);
      	$expectedDate	 = date("Y-m-d H:i:s", strtotime($newDate)); 
        $nextschduleDate = date("Y-m-d H:i:s", strtotime($dao->next_sched_contribution_date));
        // If next scheduled date is great than last payment date, then change expected date to next scheduled date
        if (!empty($nextschduleDate) && (date("Y-m-d H:i:s", strtotime($lastReceiveDate)) <= $nextschduleDate)) {
          $expectedDate = $nextschduleDate;
        }
        $recurringId = $dao->id;
        $updateQuery = "UPDATE `civicrm_temp_custom_late_recurring_search` SET expected_date = '{$expectedDate}' WHERE id = {$recurringId}";      
        CRM_Core_DAO::executeQuery($updateQuery);
               //http://support.vedaconsulting.co.uk/issues/365
        //The spec says that the search "will not look for missed payments after the recurring contribution's end date, if set."
        if (!empty($dao->end_date) && ($expectedDate > $dao->end_date)) {
          $recurringId = $dao->id;
          $deleteQuery = "DELETE FROM `civicrm_temp_custom_late_recurring_search` WHERE id = {$recurringId}";
          CRM_Core_DAO::executeQuery($deleteQuery);
        }
        //http://support.vedaconsulting.co.uk/issues/700
      	//please can you allow for a margin of error: An expected payment should count as being paid if it was received in the seven days before the expected date.
      	if ((date("Y-m-d", strtotime($lastReceiveDate)) <= date("Y-m-d", strtotime($expectedDate))) &&
      	    (date("Y-m-d", strtotime($lastReceiveDate)) >= date("Y-m-d", strtotime ( '-7 day' . $expectedDate )))) {
              $recurringId = $dao->id;
              $deleteQuery = "DELETE FROM `civicrm_temp_custom_late_recurring_search` WHERE id = {$recurringId}";
              CRM_Core_DAO::executeQuery($deleteQuery);
      	}     
      }
    }
    
  
   
    // Define the columns for search result rows
    $this->_columns = array(
      ts('Name') => 'sort_name',
      ts('Last paid') => 'receive_date',
      ts('Expected') => 'expected_date',
      ts('Amount') => 'amount',
      ts('Frequency') => 'frequency',
    );

    // define component access permission needed
    $this->_permissionedComponent = 'CiviContribute';
  }

  /**
   * @param CRM_Core_Form $form
   */
  public function buildForm(&$form) {

    /**
     * You can define a custom title for the search form
     */
    $this->setTitle('Late Recurring Contributions');

    // Get default curreny
    $config = CRM_Core_Config::singleton( );
    $currencySymbol = $config->defaultCurrencySymbol;

    /**
     * Define the search form fields here
     */
    $form->addDate('start_date', ts('Before Date'), FALSE, array('formatType' => 'custom'));

    /**
     * If you are using the sample template, this array tells the template fields to render
     * for the search form.
     */
    $form->assign('elements', array('start_date'));
  }

  /**
   * Define the smarty template used to layout the search form and results listings.
   *
   * @return string
   */
  public function templateFile() {
    return 'CRM/Contact/Form/Search/Custom.tpl';
  }

  /**
   * Construct the search query.
   *
   * @param int $offset
   * @param int $rowcount
   * @param string|object $sort
   * @param bool $includeContactIDs
   * @param bool $justIDs
   *
   * @return string
   */
  public function all(
    $offset = 0, $rowcount = 0, $sort = NULL,
    $includeContactIDs = FALSE, $justIDs = FALSE
  ) {

    // SELECT clause must include contact_id as an alias for civicrm_contact.id
    if ($justIDs) {
      $select = "contact_a.id as contact_id";
    }
    else {
      $select = "
DISTINCT contact_a.id as contact_id,
contact_a.sort_name as sort_name,
GROUP_CONCAT(contrib_recur.receive_date separator '###') as receive_date,
GROUP_CONCAT(contrib_recur.expected_date separator '###') as expected_date,
GROUP_CONCAT(contrib_recur.start_date separator '###') as start_date,
GROUP_CONCAT(contrib_recur.amount separator '###') as amount,
GROUP_CONCAT(CONCAT(contrib_recur.frequency_interval, ' ', contrib_recur.frequency_unit) separator '###') as frequency
";
    }
    $from = $this->from();
    $whereClause = "";
    $where = $this->where($includeContactIDs);
    if (!empty($where)) {
      $whereClause = 'WHERE '.$where;
    }
   
    $having = $this->having();
    if ($having) {
      $having = " HAVING $having ";
    }

    $sql = "
SELECT $select
FROM   $from
$whereClause
GROUP BY contact_a.id
$having
";

    //for only contact ids ignore order.
    if (!$justIDs) {
      // Define ORDER BY for query in $sort, with default value
      if (!empty($sort)) {
        if (is_string($sort)) {
          $sort = CRM_Utils_Type::escape($sort, 'String');
          $sql .= " ORDER BY $sort ";
        }
        else {
          $sql .= " ORDER BY " . trim($sort->orderBy());
        }
      }
      else {
        $sql .= "ORDER BY receive_date desc";
      }
    }

    if ($rowcount > 0 && $offset >= 0) {
      $offset = CRM_Utils_Type::escape($offset, 'Int');
      $rowcount = CRM_Utils_Type::escape($rowcount, 'Int');
      $sql .= " LIMIT $offset, $rowcount ";
    }
    //echo $sql;exit;
    return $sql;
  }

  /**
   * @return string
   */
  public function from() {
    return "
civicrm_contact contact_a
INNER JOIN {$this->tableName} contrib_recur ON contrib_recur.contact_id = contact_a.id
";
  }

  /**
   * WHERE clause is an array built from any required JOINS plus conditional filters based on search criteria field values.
   *
   * @param bool $includeContactIDs
   *
   * @return string
   */
  public function where($includeContactIDs = FALSE) {
    $clauses = array();

    
    $startDate = CRM_Utils_Date::processDate($this->_formValues['start_date']);
    if (empty($startDate)) {
      $startDate = date("Ymd");
    }
    if ($startDate) {
      $clauses[] = "contrib_recur.expected_date <= $startDate";
    }

    if ($includeContactIDs) {
      $contactIDs = array();
      foreach ($this->_formValues as $id => $value) {
        if ($value &&
          substr($id, 0, CRM_Core_Form::CB_PREFIX_LEN) == CRM_Core_Form::CB_PREFIX
        ) {
          $contactIDs[] = substr($id, CRM_Core_Form::CB_PREFIX_LEN);
        }
      }

      if (!empty($contactIDs)) {
        $contactIDs = implode(', ', $contactIDs);
        $clauses[] = "contact_a.id IN ( $contactIDs )";
      }
    }

    return implode(' AND ', $clauses);
  }

  /**
   * @param bool $includeContactIDs
   *
   * @return string
   */
  public function having($includeContactIDs = FALSE) {
    $clauses = array();
    $min = CRM_Utils_Array::value('min_amount', $this->_formValues);
    if ($min) {
      $min = CRM_Utils_Rule::cleanMoney($min);
      $clauses[] = "sum(contrib.total_amount) >= $min";
    }

    $max = CRM_Utils_Array::value('max_amount', $this->_formValues);
    if ($max) {
      $max = CRM_Utils_Rule::cleanMoney($max);
      $clauses[] = "sum(contrib.total_amount) <= $max";
    }

    return implode(' AND ', $clauses);
  }

  /*
   * Functions below generally don't need to be modified
   */

  /**
   * @inheritDoc
   */
  public function count() {
    $sql = $this->all();

    $dao = CRM_Core_DAO::executeQuery($sql,
      CRM_Core_DAO::$_nullArray
    );
    return $dao->N;
  }

  /**
   * @param int $offset
   * @param int $rowcount
   * @param null $sort
   * @param boolean $returnSQL Not used; included for consistency with parent; SQL is always returned
   *
   * @return string
   */
  public function contactIDs($offset = 0, $rowcount = 0, $sort = NULL, $returnSQL = TRUE) {
    return $this->all($offset, $rowcount, $sort, FALSE, TRUE);
  }

  /**
   * @return array
   */
  public function &columns() {
    return $this->_columns;
  }

  /**
   * @param $title
   */
  public function setTitle($title) {
    if ($title) {
      CRM_Utils_System::setTitle($title);
    }
    else {
      CRM_Utils_System::setTitle(ts('Search'));
    }
  }

  /**
   * @return null
   */
  public function summary() {
    return NULL;
  }

  /**
   * @param $row
   */
  public function alterRow(&$row) {
  CRM_Core_Error::debug_var('row', $row);

    $amount = $row['amount'];
    $frequency = $row['frequency'];
    $expected_date = $row['expected_date'];
    $receive_date = $row['receive_date'];
    
    if (empty($receive_date)) {
      $row['receive_date'] = '-';
    }

    $processExpectedDate = TRUE;

    if (strpos($amount,'###') !== false) {
        $row['amount'] = '*';
    }
    
    if (strpos($receive_date,'###') !== false) {
        $row['receive_date'] = '*';
    }

    if (strpos($frequency,'###') !== false) {
        $row['frequency'] = '*';   
        $row['expected_date'] = '*';
        $row['receive_date'] = '*';
        $processExpectedDate = FALSE;
    } 
  }
}
