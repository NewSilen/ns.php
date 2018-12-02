<?php 
	
	namespace newsilen\php\router\v3;

	/*
		根据类文件路径，类名称及命名空间，获取类实例对象
	*/

	/*
	class Factory {

		private $namespace;
		private $module_path;

		function __construct($namespace , $module_path){
			$this -> namespace = $namespace;
			$this -> module_path = $module_path;
		}

		function createInstance($module_name , $extra = ''){

			$path = $this -> module_path. DIRECTORY_SEPARATOR. $module_name. DIRECTORY_SEPARATOR. ucfirst($module_name). $extra. '.php';
			include $path;
			$full_class_name = '\\'.$this -> namespace.'\\'. ucfirst($module_name) . $extra;
			return new $full_class_name;

		}
	}

	*/

	class Router {
		private $jsonp = false; // 是否支持 jsonp 调用

		private $path = '';
		private $module_folder = '';
		private $addons_folder = '';
		private $namespace = '';

		public function getPath(){
			return $this-> path;
		}

		public function __construct($param = array()){

			# 初始化
			if( empty($param['path']) ){
				self::HTTP_CODE(500,'unknown dir.');
			}

			$this -> path = realpath( $param['path'] );
			$this -> module_folder = emoty($param['module']) ? 'modules' : $param['module'];
			$this -> addons_folder = emoty($param['addons']) ? 'addons' : $param['addons'];
			$this -> config_folder = emoty($param['config']) ? 'config' : $param['config'];

			$this -> namespace = '\\'. $param['namespace'];
		}

		static function analyze_pathinfo($pathinfo = ''){
			# $pathinfo 一定是以 / 开头
			$pathinfo_ary = explode('/', $pathinfo);

			if( count($pathinfo_ary) < 3){ // 个数错误
				Router::throw404('Requested URI error.');
			}
			
			# 最后一位是 action 如果 以 / 结尾 最后是空字符串，再向前一位
			$action = array_pop( $pathinfo_ary );
			if($action == ''){
				$action = array_pop( $pathinfo_ary );
			}
			$action_ary = explode('.', $action);
			$action = $action_ary[0];
			# 扩展名
			$format = count($action_ary) ==2 ? $action_ary[1] : 'json';

			$module = array_pop( $pathinfo_ary ); // action 前面是 模块名

			array_shift( $pathinfo_ary ); # 去掉第一个
			$id = array_shift( $pathinfo_ary );

			return array(
				'module'=> $module,
				'action'=> $action,
				'format' => $format,
				'id' => $id,
			);
		}

		# 从客户端提交的信息中分解出需要的信息，返回的数据按如下格式，用于定位提供服务的控制器
		function analyze(){

			if( !isset($_SERVER['PATH_INFO']) ){
				self::throw404('Requested URI error.');
			}

			return self:: analyze_pathinfo($_SERVER['PATH_INFO']);

		}


		# 分发请求到一个 controller 进行处理，返回 具名数组，处理成json字符串返回给前端
		public function dispatch(){

			$info = $this -> analyze();
			$controller = $this -> factory($info['module'] , 'Controller', $info['id'] );

		  # 检查是否存在对应的方法，不存在则返回 404
		  if(!is_callable(array($controller ,$info['action']) ) ){
		  	self::throw404('Action Not Found.');
		  }

		  # 执行方法
		  $result = $controller -> $info['action']();

		  $this -> response_array($result);
		}

		# 前端使用的 id 可以在后台设置，然后转移到特定的扩展模块
		function decode_id($id, $module){
	  	$config_path = $this -> path.DIRECTORY_SEPARATOR.$this -> config_folder.DIRECTORY_SEPARATOR.$id.'.config.php';
	  	if( file_exists($config_path)){
	  		$config = include $config_path;
	  		if( isset( $config[ $module ] )){
	  			$id = $config[ $module ];
	  		}
	  	}
	  	return $id;
		}

		# 通过指令 得到对应的对象实例
		# include_once 比 include 慢大概10倍，API的时候有必要，Controller 没有必要。 为了统一代码忽略了这个差异。
		function factory($module , $extra , $id = ''){

			# 获取模块名
			# 服务器命名规则：模块名首字母小写，对应的类名字首字母大写，需要转换。
			# 客户端提交的数据，需要使用小写规则
			// 这里就不使用 lcfirst(str) 了，客户端自己实现时候注意命名

			# 通过模块名获得对应的 controller 文件路径
			$_path_ = $this -> path.DIRECTORY_SEPARATOR.$this -> module_folder.DIRECTORY_SEPARATOR.$module.DIRECTORY_SEPARATOR.ucfirst($module).$extra.'.php';
			# 文件不存在的时候 响应 404
		  if (!file_exists($_path_) ){
		  	self::throw404('File Not Found.');
		  }

		  # 引入文件
		  include_once $_path_;
		  $_class_name_ =  $this -> namespace.'\\'.ucfirst($module).$extra;

		  if($id){
		  	$id = $this -> decode_id($id , $module);

		  	$_addon_path_ = $this -> path.DIRECTORY_SEPARATOR.$this -> addons_folder.DIRECTORY_SEPARATOR.$id.DIRECTORY_SEPARATOR.$module.DIRECTORY_SEPARATOR.ucfirst($module).$extra.'.php';
		  	if(file_exists($_addon_path_)){
		  		include_once $_addon_path_;
		  		$_class_name_ =  $this -> namespace.'\\'.$id.'\\'.ucfirst($module).$extra;
		  	}
		  }
		  
		  return new $_class_name_();
		}

		# 把执行结果响应给前端
		# 这个方法里面的逻辑不属于 router , 但还是属于框架级别的逻辑。
		# router 是无业务框架，后面应该有个业务级别的框架处理这个响应，2.0版本就不管了，先写这里。
		# TODO 框架需要处理公共的业务。所以不应该让业务方法使用 exit 中止响应导致后续代码无法执行。
		# 可以利用析构方法的机制来实现这个功能。包括记日志。
		function response_array($__result){

			# 不允许非数组出现
			# 备注：其实也可以运行非数组，但非数组结果不适合附加日志等。
			# 但是如果代码运行出错，这些机制就不适应了。
			if( !is_array($__result) ){ 
				self::HTTP_CODE(500,'runtime error.');
				exit();
			}

			# 如果显示前台日志
			if( defined('__BOXUN__FRONTLOG__') && __BOXUN__FRONTLOG__ == '1'){
				$__result['__front__'] = self:: $__log;
			}

			# jsonp 格式 [默认不支持]
			if( $this -> jsonp && isset( $_GET['callback'] ) ){
		    echo $_GET['callback'].'('.json_encode($__result,true ).')';
		  }else{
		  # json 格式
		    echo json_encode($__result,true );
		  }
		}

		# 前端调试日志
		private static $__log = array();
		static function log($content){
			array_push( self:: $__log , $content);
		}

		function api($module,$id = ''){
			return $this -> factory($module,'API',$id);
		}
		
		# 响应 http 错误码
		static function HTTP_CODE($code,$msg){
			header('HTTP/1.1 '.$code.' '.$msg);
			header('status: '.$code.' '.$msg);
		}

		# 响应 404
		static function throw404($msg){
			self::HTTP_CODE(404,$msg);
			exit();
		}

	}
?>