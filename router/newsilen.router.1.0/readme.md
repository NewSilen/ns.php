# 介绍

定义一个类，然后在类中定义一个方法，方法返回值要求是一个具名数组

```php
	class MyController {

		function index(){

			return array('errno'=>0);
		}
	}

```

前端发送`ajax请求`到服务端，服务端根据参数中的`action`执行`dispatch`方法。如果参数中包含 `callback` 则返回`jsonp数据`

```php
	
	$controller = new IndexController();
	dispatch($controller);

```