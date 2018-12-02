<?php 
	
	function dispatch($controller , $action = ''){
		$action = empty($action) ? $_REQUEST['action'] : $action;
		$__result = $controller -> $action();
		
		# jsonp 格式
		if( isset( $_GET['callback'] ) ){
	    echo $_GET['callback'].'('.json_encode($__result,true ).')';
	  }else{
	  # json 格式
	    echo json_encode($__result,true );
	  }
	}