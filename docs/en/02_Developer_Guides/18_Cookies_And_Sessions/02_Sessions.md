---
title: Sessions
summary: A set of static methods for manipulating PHP sessions.
icon: user
---
# Sessions

Session support in PHP consists of a way to preserve certain data across subsequent accesses such as logged in user
information and security tokens.

In order to support things like testing, the session is associated with a particular Controller.  In normal usage,
this is loaded from and saved to the regular PHP session, but for things like static-page-generation and
unit-testing, you can create multiple Controllers, each with their own session.
 
## set

```php
	Session::set('MyValue', 6);

```
size restrictions as to how much you can save).

```php
	// saves an array
	Session::set('MyArrayOfValues', array('1','2','3'));

	// saves an object (you'll have to unserialize it back)
	$object = new Object();
	Session::set('MyObject', serialize($object));
 
```

Once you have saved a value to the Session you can access it by using the `get` function. Like the `set` function you 
can use this anywhere in your PHP files.

```php
	echo Session::get('MyValue'); 
	// returns 6

	$data = Session::get('MyArrayOfValues'); 
	// $data = array(1,2,3)

	$object = unserialize(Session::get('MyObject', $object)); 
	// $object = Object()

```

You can also get all the values in the session at once. This is useful for debugging.
	
```php
	Session::get_all(); 
	// returns an array of all the session values.

```

Once you have accessed a value from the Session it doesn't automatically wipe the value from the Session, you have
to specifically remove it. 

```php
	Session::clear('MyValue');

```
including form and page comment information. None of this is vital but `clear_all` will clear everything.
	
```php
	Session::clear_all();

```

In certain circumstances, you may want to use a different `session_name` cookie when using the `https` protocol for security purposes. To do this, you may set the `cookie_secure` parameter to `true` on your `config.yml`

```yml
	Session:
	  cookie_secure: true

```


## API Documentation

* [api:Session]
