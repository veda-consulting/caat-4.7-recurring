<?php

/**
 * A custom contact search
 */
class CRM_Recurringcontributioncustomsearches_Form_Search_ExpectedRecurringContributionIncome extends CRM_Contact_Form_Search_Custom_Base implements CRM_Contact_Form_Search_Interface {

  protected $_formValues;
  public $_permissionedComponent;

  /**
   * @param $formValues
   */
  public function __construct(&$formValues) {
    $this->_formValues = $formValues;
    $startDate = CRM_Utils_Date::processDate($this->_formValues['start_date']);
    $endDate = CRM_Utils_Date::processDate($this->_formValues['end_date'], '235959');
    $this->tableName = 'veda_temp_custom_expected_recurring_search';
    if(CRM_Core_DAO::checkTableExists($this->tableName)) {
      $query = "DROP TABLE {$this->tableName}";
      CRM_Core_DAO::executeQuery($query);
    } 
    //KJ 15/02 As initial search form does not have start date or end date, it throws db error as we are using in query,http://support.vedaconsulting.co.uk/issues/74
    if (empty($startDate) || empty($endDate)) {
      return;
    }

    $query = "
      SELECT 
      DISTINCT civicrm_contact.id as contact_id,
      civicrm_contact.sort_name as sort_name,
      GROUP_CONCAT(civicrm_contribution_recur.amount separator '###') as amount,
      GROUP_CONCAT(CONCAT(civicrm_contribution_recur.frequency_interval, ' ', civicrm_contribution_recur.frequency_unit) separator '###') as frequency,
      GROUP_CONCAT(civicrm_contribution_recur.start_date separator '###') as donation_count,
      GROUP_CONCAT(COALESCE(civicrm_contribution_recur.end_date, 'NULL') separator '###') as donation_amount,
      GROUP_CONCAT(COALESCE(civicrm_contribution_recur.cancel_date, 'NULL') separator '###') as donation_cancel
      FROM civicrm_contribution_recur, civicrm_contact 
      WHERE civicrm_contribution_recur.contact_id = civicrm_contact.id
			AND (civicrm_contribution_recur.end_date IS NULL OR (civicrm_contribution_recur.end_date >= {$startDate} AND civicrm_contribution_recur.end_date <= {$endDate}))
			AND (civicrm_contribution_recur.cancel_date IS NULL OR (civicrm_contribution_recur.cancel_date >= {$startDate} AND civicrm_contribution_recur.cancel_date <= {$endDate}))
      GROUP BY civicrm_contact.id ORDER BY donation_amount desc";

    $creatSql =
      "CREATE TABLE {$this->tableName}
      {$query}";     
    CRM_Core_DAO::executeQuery($creatSql);  
     
    $selectQuery = "SELECT contact_id, sort_name, amount, frequency, donation_count, donation_amount, donation_cancel FROM {$this->tableName} ";
    $dao = CRM_Core_DAO::executeQuery($selectQuery);
     
    while($dao->fetch()) {
      $row = array();
      //$startDate = CRM_Utils_Date::processDate($this->_formValues['start_date']);
     // $endDate = CRM_Utils_Date::processDate($this->_formValues['end_date'], '235959');

      $amount = $dao->amount;
      $frequency = $dao->frequency;

      // HACK: having the start date in count column
      $recur_start_date_str = $dao->donation_count;

      // HACK: having the end date in amount column
      $recur_end_date_str = $dao->donation_amount;
    
      $recur_cancel_date_str = $dao->donation_cancel;
      $amountUpdate = '';
      $frequencyUpdate = '';
      if (strpos($amount,'###') !== false) {
	  $amountUpdate = '*';
      }

      if (strpos($frequency,'###') !== false) {
	  $frequencyUpdate = '*';   
      }

      $frequencies = @explode('###', $frequency);
      $amounts = @explode('###', $amount);
      $recur_start_dates = @explode('###', $recur_start_date_str);
      $recur_end_dates = @explode('###', $recur_end_date_str);
      $recur_cancel_dates = @explode('###', $recur_cancel_date_str);
			
			/*CRM_Core_Error::debug_var('$recur_cancel_dates', $recur_cancel_dates);
      // Only for multiple recurring for a contact
      if (count($recur_cancel_dates) > 1) {
        $tmp = array_count_values($recur_cancel_dates);
        $cnt = $tmp['NULL'];
        //Check if only one valid record
        if ($cnt == 1) {
          $amountUpdate = $amounts[array_search('NULL', $recur_cancel_dates)];
          $frequencyUpdate = $frequencies[array_search('NULL', $recur_cancel_dates)];
          CRM_Core_Error::debug_var('$amountUpdate', $amountUpdate);
        }
      }
			
			if (count($recur_end_dates) > 1) {
        $tmp = array_count_values($recur_end_dates);
        $cnt = $tmp['NULL'];
        //Check if only one valid record
        if ($cnt == 1) {
          $amountUpdate = $amounts[array_search('NULL', $recur_end_dates)];
          $frequencyUpdate = $frequencies[array_search('NULL', $recur_end_dates)];
          CRM_Core_Error::debug_var('$amountUpdate', $amountUpdate);
        }
      }*/
			
      $startTimeStamp =  strtotime($startDate);
      $endTimeStamp =  strtotime($endDate);

      $min_date = min($startTimeStamp, $endTimeStamp);
      $max_date = max($startTimeStamp, $endTimeStamp);
    
      if ($dao->frequency) {
	$donation_count = $donation_amount = 0;  
	foreach ($frequencies as $frequency_id => $frequency_value) {
	  $recurEndDateTimeStamp = '';
	  $recurStartDate = CRM_Utils_Date::processDate($recur_start_dates[$frequency_id]);
	  $recurCancelDate = CRM_Utils_Date::processDate($recur_cancel_dates[$frequency_id]);
	  $recurStartDateTimeStamp =  strtotime($recurStartDate);
	  $recurCancelDateTimeStamp =  strtotime($recurCancelDate);

	  if (!empty($recur_end_dates[$frequency_id]) && $recur_end_dates[$frequency_id] != 'NULL') {
	    $recurEndDate = CRM_Utils_Date::processDate($recur_end_dates[$frequency_id]);
	    $recurEndDateTimeStamp =  strtotime($recurEndDate);
	  } else {
	    $recurEndDateTimeStamp = $max_date;
	  }

	  $i = 0;

	  $frequency_duration = '+'.strtoupper($frequency_value);
	 // check if every recurring payment date is in the user selected date range and below its end date/user selected end date
	  do {
	    if (($recurStartDateTimeStamp >= $min_date) && ($recurStartDateTimeStamp <= $max_date) && ($recurStartDateTimeStamp <= $recurEndDateTimeStamp) ) {
	      $minDateTemp = date('d-m-Y H:i:s', $recurStartDateTimeStamp);
	      $maxDateTemp = date('d-m-Y H:i:s', $max_date);
	      // if recurring payment has cancel date then check it falls below cancel date
	      if ($recurCancelDateTimeStamp) {
		if ($recurStartDateTimeStamp < $recurCancelDateTimeStamp) {
		  $i++;
		}
	      } else {
		$i++;
	      }
	    } 
	      //echo $row['sort_name']." - {$minDateTemp} - {$maxDateTemp}<br />";
	  } while (($recurStartDateTimeStamp = strtotime("{$frequency_duration}", $recurStartDateTimeStamp)) < $max_date);

	  $donation_count = $donation_count + $i;
	  $donation_amount = $donation_amount + ($i * $amounts[$frequency_id]);
	}
	$donation = $donation_count;
	$donationAmount = $donation_amount;
      } else {
	$donation = '';
	$donationAmount = '';
      }
      $contact_id = $dao->contact_id;
      $updateQuery = "UPDATE {$this->tableName} SET donation_count = {$donation}, donation_amount = {$donationAmount} WHERE contact_id = {$contact_id} ";      
      if (!empty($frequencyUpdate)) {
	$updateQuery = "UPDATE {$this->tableName} SET frequency = '{$frequencyUpdate}', amount = '{$amountUpdate}', donation_count = {$donation}, donation_amount = {$donationAmount} WHERE contact_id = {$contact_id} ";
      }      
      CRM_Core_DAO::executeQuery($updateQuery);      
    }  
  // Delete If donation_count is Zero
    $deleteQuery = "DELETE FROM {$this->tableName} WHERE donation_count = 0";
    CRM_Core_DAO::executeQuery($deleteQuery);

    // Define the columns for search result rows
    $this->_columns = array(
      ts('Name') => 'sort_name',
      ts('Amount') => 'amount',
      ts('Frequency') => 'frequency',
      ts('Payments') => 'donation_count',
      ts('Total Amount') => 'donation_amount',
      '' => 'donation_cancel',

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
    $this->setTitle('Expected Recurring Contribution Income');

    /**
     * Define the search form fields here
     */
    $form->addDate('start_date', ts('Start Date'), TRUE, array('formatType' => 'custom'));
    $form->addDate('end_date', ts('End Date'), TRUE, array('formatType' => 'custom'));

    /**
     * If you are using the sample template, this array tells the template fields to render
     * for the search form.
     */
    $form->assign('elements', array('start_date', 'end_date'));
    $form->addFormRule(array('CRM_Recurringcontributioncustomsearches_Form_Search_ExpectedRecurringContributionIncome', 'formRule'), $form);
  }
  
   static function formRule($params, $files, $self) {
    $errors     = array();
    $startDate  = strtotime($params['start_date']);
    $endDate    = strtotime($params['end_date']);
    // Check end date greater than start date
    if (date("Y-m-d", $endDate) < date("Y-m-d", $startDate)) {
      $errors['_qf_default'] = 'End date must be greater than start date';
    }
    if (!empty($errors)) {
      return $errors;
    }
    return TRUE;
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
contact_id,
sort_name,
amount,
frequency,
donation_count,
donation_amount,
donation_cancel
";
    }

    $from = $this->from();

    $where = $this->where($includeContactIDs);
    
    $having = $this->having();
    if ($having) {
      $having = " HAVING $having ";
    }

    $sql = "
SELECT $select
FROM   $from

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
        $sql .= "ORDER BY donation_amount desc";
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
{$this->tableName} 
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

  }
  
}

