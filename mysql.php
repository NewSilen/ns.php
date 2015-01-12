<?php
	# 数据库基本库
	// 提供数据库连接 === 使用单例模式 避免重复申请连接
	class MySQLConnPool{
		private static $con;
		private static $_instance;
		public function __construct(){
			$con = mysql_connect(MYSQL_URL, MYSQL_USERNAME, MYSQL_PASSWORD);
			if (!$con){
			  die('Could not connect: ' . mysql_error());
			}

			mysql_select_db(MYSQL_DB_NAME, $con);
			self::$con = $con;
		}
		# 销毁连接
		function __destruct(){
			mysql_close(self::$con);
		}

		public static function getConn(){
			if(! (self::$_instance instanceof self) ) {
	            self::$_instance = new self();
	        }
	        return self::$con;
		} 
	}

	// $con = MySQLConnPool::getConn();
	/**
		数据库表 抽象类，子类定义属性对应表字段
	*/
	abstract class NsTable{
		/* 
			以子类名为key 缓存表名和ORM关系，避免每次获取都要重新计算，此属性不继承
			array("name","tableName","fieldMap":array(...));
		*/
		protected static $__tableInfomation;
		/*
			提供表名
			默认通过子类名称获取表的名字， 子类和表名的约定规则： TestTable => test_table
			子类也可以通过重载此方法主动提供表名
			如果命名中包含数字，数字会当做小写字母处理，所以命名 OAUTH_2INFO无法识别，能识别的： Oauth2Info <-> OAUTH2_INFO
		*/
		public function getName(){
			$className = get_class($this);// 当前子类名称
			if(empty($this::$__tableInfomation)){
				$this::$__tableInfomation = array();
			}
			if(empty($this::$__tableInfomation[$className])){
				$this::$__tableInfomation[$className] = array();
			}
			if(empty($this::$__tableInfomation[$className]['name'])){
				$this::$__tableInfomation[$className]['name'] =
					strtolower(preg_replace('/((?<=[a-z0-9])(?=[A-Z]))/', '_',$className));
			}
			
			return $this::$__tableInfomation[$className]['name'];
		}

		public function getId(){return $this->id;}
		public function setId($id){$this->id=$id;}
		# orm 对应关系，数组表示，以对象字段为准，如果关系中没有此字段，则使用字段名作为数据库表名
		# 默认数据库表主键字段名是 【id】，不需要设置
		# 如果数据库中的 主键名 不是id，在map中也必须设置为 id；对象的属性名中 id 是固定的
		public function getFieldMap(){
			$className = get_class($this);// 当前子类名称
			if(empty($this::$__tableInfomation)){
				$this::$__tableInfomation = array();
			}
			if(empty($this::$__tableInfomation[$className])){
				$this::$__tableInfomation[$className] = array();
			}
			if(empty($this::$__tableInfomation[$className]['fieldMap'])){
				$map = array();
				foreach ($this as $key => $fieldName) {
					$map[$key] = strtoupper((preg_replace('/((?<=[a-z0-9])(?=[A-Z]))/', '_',$key)));
				}

				$this::$__tableInfomation[$className]['fieldMap'] = $map;
			}

			return $this::$__tableInfomation[$className]['fieldMap'];
		}
	}

	# 数据库操作公共类
	class NsService{
		private $con;
		public function __construct(){
			$this -> con = MySQLConnPool::getConn();
		}

		/*  
			@desc 插入一条数据，返回新插入数据的 id
			@param $tableRecord 一行表数据
			@param $record：key-value 数组，key是字段名，value是对应要插入的值
		 	@return 新数据 id
		*/
		protected function insert($tableRecord){
			$tableName = $tableRecord -> getName();# 获得表名
			$fieldMap = $tableRecord -> getFieldMap(); # orm 对应关系

			$id = $tableRecord -> getId(); # id 非自增时可以获得当前id用于返回结果

			$columnNames = array();
			$values = array();
			foreach ($tableRecord as $key => $value) {
				if(isset($value)){ # 设置了要保持的值
					$columnName = isset($fieldMap[$key]) ? $fieldMap[$key] : $key; # 列名未设置 默认使用字段名
					array_push($columnNames, $columnName);
					array_push($values,  mysql_escape_string($value)); # 对特殊字符转义，防止注入或者输入错误
				}
			}

			if(empty($columnNames)){die("<br/>[No data to be insert to table ".$tableName);} # 没有数据时进行报错
			$sql = "insert into ".$tableName." (`".join($columnNames,"`,`")."`) values ('".join($values,"','")."')";
			if(NS_DEBUG){ns_log($sql);}
			if(!mysql_query($sql,$this-> con)){
				ns_log($sql,'SQL ERROR');
				die('[Service insert Err]{"err":"'.mysql_error().'","sql":"'.$sql.'"}<br/>');
			}
			if(isset($id)){ return $id; }
			$newId = mysql_insert_id(); # mysql_insert_id 只对自增的id有效
			$tableRecord -> setId($newId); # 更新表数据 id
			return $newId;
		}

		# 对一张表进行批量插入，返回执行结果 成功(T)|失败(F)
		protected function insertList($records){
			// 没有数据要插入时直接跳过此部分执行后续代码，返回 true
			if(count($records)==0){return true;}
			# 批量插入时需要所有的数据是同一个表的
			# 从第一条数据获取表信息
			$tableName = $records[0]-> getName();
			$fieldMap = $records[0] -> getFieldMap();
			# 获得字段列表，按照实体类的定义顺序
			$columnNames = array();
			foreach ($records[0] as $key => $value) {
				$columnName = isset($fieldMap[$key]) ? $fieldMap[$key] : $key; # 列名未设置 默认使用字段名
				array_push($columnNames, $columnName);
			}

			# 批量插入需要把所有数据字段都进行插入
			# 数据库中设置了默认值的话 - 对象中是null 则插入null，会导致错误，所以要求批量插入的表处理默认值
			# 包括在构造函数中设置默认值或者生成对象后再设置。
			
			$values = array(); // 全部数据
			foreach($records as $oneRow){ # 依次处理每行数据
				$oneRowValues = array(); // 单行数据
				foreach ($oneRow as $cn => $value) { # 依次处理每个字段值---这里的顺序要和上面的字段名保持一致
					if(isset($value)){
						# 设置了要保存的值，则保留该值并用单引号包围
						array_push($oneRowValues,"'". mysql_escape_string($value). "'"); # 对特殊字符转义，防止注入或者输入错误
					}else{
						# 没有设置则保存null,
						# 这里会出现数据库不允许为null的字段同时设置了默认值的情况，这个要求在代码中赋予默认值，否则会出错
						array_push($oneRowValues,'null');
					}
				}
				array_push($values,"(".join($oneRowValues,',').")"); // 保存单行数据结果
			}
			$sql = "insert into ".$tableName." (`".join($columnNames,"`,`")."`) values ".join($values,",");
			if(NS_DEBUG){ns_log($sql);}
			if(!mysql_query($sql,$this -> con)){
				ns_log($sql,'SQL ERROR');
				die('[Service insertList Err]{"err":"'.mysql_error().'","sql":"'.$sql.'"}<br/>');
			}
			return true; // 有返回值的话 就只有true，失败时 直接调用了 die方法中断了后续代码的执行。
		}

		/* 
			@desc 修改一条数据，按照id 查找数据 ，并将所有数据都修改掉
				多用于修改表单数据
			@param $tableRecord 记录对象
			@param $filter ： 过滤字段名数组
			@param $flag：控制过滤字段 true表示过滤字段不修改；false 表示过滤字段需要修改。
		*/
		protected function update($tableRecord,$filter=array(),$flag=true){
			$id = $tableRecord-> getId();
			if(empty($id)){
				die('[The id is empty]'.$tableRecord-> getName());
			} // 不允许 id 为空（包括 0）的数据存在，直接报错即可。

			$fieldMap = $tableRecord -> getFieldMap();
			$idCoulumnName = isset($fieldMap['id']) ? $fieldMap['id'] : "id";// 默认名 id
			$tableName = $tableRecord -> getName();

			$u = array();
			foreach ($tableRecord as $fieldName => $value) { // 循环每个字段，找出需要修改的依次处理
				if( $fieldName == "id" ){continue;} # id 不在修改访问内
				# 处理过滤字段
				if(!empty($filter) && in_array($fieldName, $filter)==$flag){continue;}

				$columnName = isset($fieldMap[$fieldName]) ? $fieldMap[$fieldName] : $fieldName;// 通过字段名获得列名
				if(isset($value)){
					array_push($u, "`".$columnName."` = '".mysql_escape_string($value)."'");
				}else{
					array_push($u, "`".$columnName."` = null");
				}
			}
			if(empty($u)){die("[No data to be updated.]");}
			$sql = "update $tableName set ".join($u,",")." where $idCoulumnName='$id'";
			if(!mysql_query($sql,$this->con)){
				die('[Service Update Err]{"err":"'.mysql_error().'","sql":"'.$sql.'"}<br/>');
			}
			return mysql_affected_rows($this->con);
		}

		public function query($sql){
			$result = mysql_query($sql,$this->con);
			if(!$result){
				die('[Service Query Err]{"err":"'.mysql_error().'","sql":"'.$sql.'"}<br/>');
			}
			return $result;
		}

		// 事务等其他
	}

	class NsController{
		// 自动填充数据
		public static function fillEntity($template,$ary = array()){
			// 生成一个新的实例
			if(empty($ary))$ary = $_POST;
			if(!empty($ary)){
				if(!isset($template->id) && !empty($ary['id'])){ 
					$template -> setId($ary['id']);
				}

				// set other fields.
				foreach ($template as $fieldName => $value) {
					if(isset($ary[$fieldName])){
						$template -> $fieldName = $ary[$fieldName];
					}
				}
			}

			return $template;
		}
	}
?>