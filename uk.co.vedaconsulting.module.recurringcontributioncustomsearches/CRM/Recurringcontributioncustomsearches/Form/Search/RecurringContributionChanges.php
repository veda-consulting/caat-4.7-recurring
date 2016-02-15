<?php

/**
 * A custom contact search
 */
class CRM_Recurringcontributioncustomsearches_Form_Search_RecurringContributionChanges extends CRM_Contact_Form_Search_Custom_Base implements CRM_Contact_Form_Search_Interface {

  protected $_formValues;
  public $_permissionedComponent;

  /**
   * @param $formValues
   */
  public function __construct(&$formValues) {
    $this->_formValues = $formValues;
    $clauses = array();
    $clauses[]  = "contrib.total_amount != contrib_recur.amount";
    $clauses[]  = "contrib.is_test = 0";
    $clauses[]  = "(contrib.contribution_status_id = 1 OR contrib.contribution_status_id = 8)";
    $value      = isset($this->_formValues['start_date']) ? $this->_formValues['start_date'] : '';
    if ($changeDate = CRM_Utils_Date::processDate($value)) {
      $clauses[] = "contrib.receive_date >= $changeDate";
    }
    $whereClause = implode(' AND ', $clauses);
    $this->tableName = 'civicrm_temp_custom_recurring_change_search';
    if(CRM_Core_DAO::checkTableExists($this->tableName)) {
      $query = "DROP TABLE {$this->tableName}";
      CRM_Core_DAO::executeQuery($query);
    } 
    $creatSql =
      "CREATE TABLE {$this->tableName}
       SELECT contrib.id as contribution_id, contact_a.id as contact_id,
       contact_a.sort_name as sort_name,
       contrib_recur.amount as new_amount,
       CONCAT(contrib_recur.frequency_interval, ' ', contrib_recur.frequency_unit) as frequency,
       contrib.receive_date as change_date,
       contrib.total_amount AS old_amount
       FROM civicrm_contact contact_a 
       INNER JOIN civicrm_contribution_recur contrib_recur ON (contrib_recur.contact_id = contact_a.id AND (contrib_recur.end_date IS NULL OR contrib_recur.end_date >= NOW()) AND (contrib_recur.cancel_date IS NULL OR contrib_recur.cancel_date >= NOW()) )
       INNER JOIN civicrm_contribution contrib ON contrib.contribution_recur_id = contrib_recur.id
       WHERE {$whereClause}";
    CRM_Core_DAO::executeQuery($creatSql);
    
    // Define the columns for search result rows
    $this->_columns = array(
      ts('Name') => 'sort_name',
      ts('Old Amount') => 'old_amount',
      ts('Changed Since') => 'change_date',
      ts('New Amount') => 'new_amount',
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
    $this->setTitle('Recurring Contribution Changes');

    /**
     * Define the search form fields here
     */
    $form->addDate('start_date', ts('Changes Since'), FALSE, array('formatType' => 'custom'));

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
temp.contact_id ,
temp.sort_name ,
temp.new_amount ,
temp.frequency ,
temp.change_date,
temp.old_amount 
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
        $sql .= "ORDER BY new_amount desc";
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
{$this->tableName} AS temp
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
    $clauses[] = "temp.change_date = (SELECT MAX(t2.change_date)FROM {$this->tableName} t2 WHERE t2.contact_id = temp.contact_id)  ";

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
    $row['change_date'] = CRM_Utils_Date::customFormat($row['change_date']);
  }
}
