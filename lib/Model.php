﻿<?php
/**
 * Wrapper class for MySQL database interactions
 * 
 * */
class Model
{
	private $savedQueries = array();
	public static $db;
	public static $numQueries = 0;
	public static $failedQueries = 0;
	public $error = null;
	public static $queryCache = array();
	public static $cacheMode = true;
	public static $queryLog = array();
	public static $indexes = array();

	function __construct()
	{
		if(!self::$db){
			$this->db = new PDO('mysql:host='.MYSQL_HOST.';dbname='.MYSQL_DB.';charset=utf8', MYSQL_USER, MYSQL_PASS);
			$this->db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
			$getIndexList = $this->fetchAll('SELECT TABLE_NAME, INDEX_NAME, COLUMN_NAME
											FROM   information_schema.STATISTICS
											WHERE  TABLE_SCHEMA = DATABASE()');
			foreach($getIndexList as $index){
				if($index['INDEX_NAME'] == 'PRIMARY'){
					self::$indexes[$index['TABLE_NAME']] = $index['COLUMN_NAME'];
				}
			}
			
		}
	}
	/**
	* Prepare and execute an sql query
	*
	*/
	public function sendQuery($sql, $values = array())
	{
		$logKey = md5($sql);
		if(!isset(self::$queryLog[$logKey])){
			self::$queryLog[$logKey] = array('sql' => $sql, 'count' => 0, 'values' => array() );
		}
		self::$queryLog[$logKey]['values'][] = $values;
		self::$queryLog[$logKey]['count']++;
		
		$query = $this->db->prepare($sql);
		if(!$query){
			self::$failedQueries++;
			return false;
		}
		$execute = $query->execute($values);
		if(!$execute){
			$this->error = $query->errorInfo();
			
			self::$failedQueries++;
			
			return false;
		}

		self::$numQueries++;
		
		return $query;

	}
	/**
	* Execute a query and fetch an array containing row info
	*
	*/
	public function fetchSingle($sql, $values = array(), $obj = 0, $noCache = false)
	{
		if(!$noCache AND self::$cacheMode){
			$qSig = md5('FETCH'.$sql.serialize($values));
			if(isset(self::$queryCache[$qSig])){
				return self::$queryCache[$qSig];
			}		
		}
		$query = $this->sendQuery($sql, $values);
		if($obj == 0){
			$fetch = $query->fetch(PDO::FETCH_ASSOC);
		}
		else{
			$fetch =  $query->fetch(PDO::FETCH_OBJ);
		}
		if(!$noCache AND self::$cacheMode){
			self::$queryCache[$qSig] = $fetch;
		}
		return $fetch;
	}
	/**
	* Execute query and fetch a list of rows that match the query
	*
	*/
	public function fetchAll($sql, $values = array(), $obj = 0, $noCache = false)
	{
		if(!$noCache AND self::$cacheMode){
			$qSig = md5('FETCHALL'.$sql.serialize($values));
			if(isset(self::$queryCache[$qSig])){
				return self::$queryCache[$qSig];
			}	
		}
		$query = $this->sendQuery($sql, $values);
		if($obj == 0){
			$fetch = $query->fetchAll(PDO::FETCH_ASSOC);
		}
		else{
			$fetch = $query->fetchAll(PDO::FETCH_OBJ);
		}
		if(!$noCache AND self::$cacheMode){
			self::$queryCache[$qSig] = $fetch;
		}
		return $fetch;
	}

	/**
	*
	* if a query was already made, use this function to grab the saved version
	*
	*/
	public function getSavedQuery($key)
	{

		if(!isset($this->savedQueries[$key])){
			return false;
		}

		return $this->savedQueries[$key];

	}

	/**
	*
	* saves a mysql query to prevent duplicate queries on the same page
	*
	*
	*/
	public function saveQuery($key, $value)
	{
		$this->savedQueries[$key] = $value;
		return true;

	}
	
	public function getParams($array = array())
	{
		$output = array();
		
		foreach($array as $key => $val){
			$output[':'.$key] = $val;
		}
		
		return $output;
	}
	
	public function insert($table, $data)
	{
		$fields = array();
		$params = array();
		foreach($data as $key => $val){
			$fields[] = $key;
			$params[] = ':'.$key;
		}
		
		$sql = 'INSERT INTO '.$table.'('.join(',', $fields).') VALUES('.join(',', $params).')';
		$insert = $this->sendQuery($sql, $this->getParams($data));
		
		if(!$insert){
			return false;
		}
		
		return $this->db->lastInsertId($table);
	}
	
	public function edit($table, $id, $data, $indexName = '')
	{
		if($indexName == ''){
			$indexName = self::$indexes[$table];
		}
		
		if($indexName == ''){
			return false;
		}
		
		$fields = array();
		foreach($data as $key => $val){
			$fields[] = $key.' = :'.$key;
		}
		
		$sql = 'UPDATE '.$table.' SET '.join(', ', $fields).' WHERE '.$indexName.' = :index';
		$params = $this->getParams($data);
		$params[':index'] = $id;
		return $this->sendQuery($sql, $params);
	}
	
	public function delete($table, $id, $indexName = '')
	{
		if($indexName == ''){
			$indexName = self::$indexes[$table];
		}
		
		if($indexName == ''){
			return false;
		}
		
		$sql = 'DELETE FROM '.$table.' WHERE '.$indexName.' = :index';
		return $this->sendQuery($sql, array(':index' => $id));
	}
	
	public function getAll($table, $wheres = array(), $fields = array(), $orderBy = '', $orderDir = 'desc', $limit = false, $limitStart = 0)
	{
		$where = '';
		$whereValues = array();
		$values = array();
		if(count($wheres) > 0){
			foreach($wheres as $key => $val){
				if(is_array($val)){
					if(isset($val['op']) AND isset($val['value'])){
						switch($val['op']){
							case '=':
							case '==':
								$useOp = '=';
								break;
							case '<=':
								$useOp = '<=';
								break;
							case '<':
								$useOp = '<';
								break;
							case '>=':
								$useOp = '>=';
								break;
							case '>':
								$useOp = '>';
								break;
							case '!':
							case '!=';
								$useOp = '!=';
								break;
							default:
								//invalid operator, move on
								$useOp = false;
								break;
						}
						if(!$useOp){
							continue;
						}
						$whereValues[] = $key.' '.$useOp.' :'.$key;
						$values[':'.$key] = $val['value'];
					}
					else{
						foreach($val as &$v){
							if(!is_int($v)){
								$v = '"'.addslashes(trim($v)).'"';
							}
						}
						$whereValues[] = $key.' IN('.join(',', $val).')';
					}
				}
				else{
					$whereValues[] = $key.' = :'.$key;
					$values[':'.$key] = $val;
				}
			}
			$where = ' WHERE '.join(' AND ', $whereValues);
		}
		
		$andOrder = '';
		if($orderBy != ''){
			$andOrder = ' ORDER BY '.$orderBy.' '.$orderDir;
		}
		
		$getFields = '*';
		if(count($fields) > 0){
			$getFields = join(',', $fields);
		}
		
		$andLimit = '';
		if($limit != false){
			$andLimit = ' LIMIT '.$limitStart.', '.$limit;
		}
		
		$sql = 'SELECT '.$getFields.' FROM '.$table.$where.$andOrder.$andLimit;
		return $this->fetchAll($sql, $values);
	}
	

	
	public function get($table, $id, $fields = array(), $indexName = '')
	{
		if($indexName == ''){
			$indexName = self::$indexes[$table];
		}
		
		if($indexName == ''){
			return false;
		}
		
		$getFields = '*';
		if(count($fields) > 0){
			$getFields = join(',', $fields);
		}
		
		$sql = 'SELECT '.$getFields.' FROM '.$table.' WHERE '.$indexName.' = :index';
		return $this->fetchSingle($sql, array(':index' => $id));
		
	}
	
	public function count($table, $field = '', $value = '')
	{
		$where = '';
		$values = array();
		if($field != ''){
			if(is_array($field)){
				$where = '';
				$fnum = 0;
				foreach($field as $f => $v){
					if($fnum == 0){
						$where .= ' WHERE '.$f.' = :'.$f.' ';
					}
					else{
						$where .= ' AND '.$f.' = :'.$f.' ';
					}
					$values[':'.$f] = $v;
					$fnum++;
				}
			}
			else{
				$where = 'WHERE '.$field.' = :'.$field;
				$values[':'.$field] = $value;
			}

		}
		
		$sql = 'SELECT count(*) as total FROM '.$table.' '.$where;
		$fetch = $this->fetchSingle($sql, $values);
		if(!$fetch){
			return false;
		}
		
		return $fetch['total'];
	}
	
	public function sum($table, $field, $id = '', $index = '')
	{
		$where = '';
		$values = array();
		if($id != '' AND $index != ''){
			$where = ' WHERE '.$index.' = :'.$index;
			$values[':'.$index] = $id;
		}
		
		$sql = 'SELECT SUM('.$field.') as total FROM '.$table.$where;
		$fetch = $this->fetchSingle($sql, $values);
		if(!$fetch){
			return false;
		}
		
		return $fetch['total'];
		
	}
	
	public function truncate($table)
	{
		$sql = 'TRUNCATE '.$table;
		return $this->sendQuery($sql);
	}

}

?>
