<?php

/**
 * A custom contact search
 */
class CRM_Recurringcontributioncustomsearches_Form_Search_RecurringContributionAggregate extends CRM_Contact_Form_Search_Custom_Base implements CRM_Contact_Form_Search_Interface {

  protected $_formValues;
  public $_permissionedComponent;

  /**
   * @param $formValues
   */
  public function __construct(&$formValues) {
    $this->_formValues = $formValues;

    // Define the columns for search result rows
    $this->_columns = array(
      ts('Name') => 'sort_name',
      ts('Amount') => 'amount',
      ts('Frequency') => 'frequency',
      ts('Since ') => 'start_date',
      ts('Payments') => 'donation_count',
      ts('Total Amount') => 'donation_amount',

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
    $this->setTitle('Recurring Contribution Aggregate');

    // Get default curreny
    $config = CRM_Core_Config::singleton( );
    $currencySymbol = $config->defaultCurrencySymbol;

    /**
     * Define the search form fields here
     */
    $form->addDate('start_date', ts('Start Date'), FALSE, array('formatType' => 'custom'));

    $form->add('text',
      'min_amount',
      ts('Aggregate Total Between '.$currencySymbol)
    );
    $form->addRule('min_amount', ts('Please enter a valid amount (numbers and decimal point only).'), 'money');

    $form->add('text',
      'max_amount',
      ts('...and '.$currencySymbol)
    );
    $form->addRule('max_amount', ts('Please enter a valid amount (numbers and decimal point only).'), 'money');

    /**
     * If you are using the sample template, this array tells the template fields to render
     * for the search form.
     */
    $form->assign('elements', array('start_date', 'min_amount', 'max_amount'));
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
GROUP_CONCAT(contrib_recur.amount separator '###') as amount,
GROUP_CONCAT(CONCAT(contrib_recur.frequency_interval, ' ', contrib_recur.frequency_unit) separator '###') as frequency,
GROUP_CONCAT(contrib_recur.id separator '###') as start_date,
count(contrib.id) AS donation_count,
sum(contrib.total_amount) AS donation_amount
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
WHERE  $where
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
        $sql .= "ORDER BY donation_amount desc";
      }
    }

    if ($rowcount > 0 && $offset >= 0) {
      $offset = CRM_Utils_Type::escape($offset, 'Int');
      $rowcount = CRM_Utils_Type::escape($rowcount, 'Int');
      $sql .= " LIMIT $offset, $rowcount ";
    }
    return $sql;
  }

  /**
   * @return string
   */
  public function from() {
    return "
civicrm_contribution AS contrib,
civicrm_contribution_recur AS contrib_recur,
civicrm_contact AS contact_a
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

    $clauses[] = "contrib.contribution_recur_id = contrib_recur.id";
    $clauses[] = "contrib.contact_id = contact_a.id";
    $clauses[] = "contrib.is_test = 0";
    $clauses[] = "(contrib.contribution_status_id = 1 OR contrib.contribution_status_id = 8)";
    $clauses[] = "contrib.contribution_recur_id IS NOT NULL AND (contrib_recur.end_date >= NOW() OR contrib_recur.end_date IS NULL)";
    $clauses[] = "contrib.contact_id IN (SELECT contact_id FROM civicrm_contribution_recur WHERE cancel_date IS NULL)";
    
    $startDate = CRM_Utils_Date::processDate($this->_formValues['start_date']);
    if ($startDate) {
      $clauses[] = "contrib.receive_date >= $startDate";
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

    // HACK: Having  Recurring contribution ID in start date
    $recurringId = $row['start_date'];
    $recurringId = str_replace('###', ',', $recurringId);
    $sql = "SELECT MIN(receive_date) as min_receive_date FROM civicrm_contribution WHERE contribution_recur_id IN ($recurringId)";
    $dao = CRM_Core_DAO::executeQuery( $sql);
    if ($dao->fetch()) {
      $row['start_date'] = CRM_Utils_Date::processDate($dao->min_receive_date);
    } 

    $row['start_date'] = CRM_Utils_Date::customFormat($row['start_date']);

    $amount = $row['amount'];
    $frequency = $row['frequency'];

    if (strpos($amount,'###') !== false) {
        $row['amount'] = '*';
    }

    if (strpos($frequency,'###') !== false) {
        $row['frequency'] = '*';   
    }

    if (isset($_POST['start_date']) && !empty($_POST['start_date'])) {
      $rowStartTimeStamp = strtotime($row['start_date']);
      $fromStartTime = CRM_Utils_Date::processDate($_POST['start_date']);
      $fromStartTimeStamp = strtotime($_POST['start_date']);

      if ($fromStartTimeStamp > $rowStartTimeStamp) {
        $row['start_date'] = CRM_Utils_Date::customFormat($fromStartTime);
      }
    }
  }
}
