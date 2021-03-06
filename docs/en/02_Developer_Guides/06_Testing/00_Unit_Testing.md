---
title: Unit and Integration Testing
summary: Test models, database logic and your object methods.
---
# Unit and Integration Testing

A Unit Test is an automated piece of code that invokes a unit of work in the application and then checks the behavior 
to ensure that it works as it should. A simple example would be to test the result of a PHP method.

**mysite/code/Page.php**

```php
	<?php

	class Page extends SiteTree {

		public static function MyMethod() {
			return (1 + 1);
		}
	}

```

```php
	<?php

	class PageTest extends SapphireTest {

		public function testMyMethod() {
			$this->assertEquals(2, Page::MyMethod());
		}
	}

```
Tests for your application should be stored in the `mysite/tests` directory. Test cases for add-ons should be stored in 
the `(modulename)/tests` directory. 

Test case classes should end with `Test` (e.g PageTest) and test methods must start with `test` (e.g testMyMethod).
[/info]

A SilverStripe unit test is created by extending one of two classes, [api:SapphireTest] or [api:FunctionalTest]. 

[api:SapphireTest] is used to test your model logic (such as a `DataObject`), and [api:FunctionalTest] is used when 
you want to test a `Controller`, `Form` or anything that requires a web page.

[info]
`FunctionalTest` is a subclass of `SapphireTest` so will inherit all of the behaviors. By subclassing `FunctionalTest`
you gain the ability to load and test web pages on the site. 

`SapphireTest` in turn, extends `PHPUnit_Framework_TestCase`. For more information on `PHPUnit_Framework_TestCase` see 
the [PHPUnit](http://www.phpunit.de) documentation. It provides a lot of fundamental concepts that we build on in this 
documentation.
[/info]

## Running Tests

### PHPUnit Binary

The `phpunit` binary should be used from the root directory of your website.

```bash
	phpunit
	# Runs all tests
	
	phpunit framework/tests/
	# Run all tests of a specific module

	phpunit framework/tests/filesystem
	# Run specific tests within a specific module
	
	phpunit framework/tests/filesystem/FolderTest.php
	# Run a specific test
	
	phpunit framework/tests '' flush=all
	# Run tests with optional `$_GET` parameters (you need an empty second argument)

```
The manifest is not flushed when running tests. Add `flush=all` to the test command to do this (see above example.)
[/alert]

[alert]
If phpunit is not installed globally on your machine, you may need to replace the above usage of `phpunit` with the full
path (e.g `vendor/bin/phpunit framework/tests`)
[/alert]

[info]
All command-line arguments are documented on [phpunit.de](http://www.phpunit.de/manual/current/en/textui.html).
[/info]
	
### Via a Web Browser

Executing tests from the command line is recommended, since it most closely reflects test runs in any automated testing 
environments. If for some reason you don't have access to the command line, you can also run tests through the browser.
	
```
	http://yoursite.com/dev/tests

```
### Via the CLI

The [sake](../cli) executable that comes with SilverStripe can trigger a customised [api:TestRunner] class that 
handles the PHPUnit configuration and output formatting. While the custom test runner a handy tool, it's also more 
limited than using `phpunit` directly, particularly around formatting test output.

```bash
	sake dev/tests/all
	# Run all tests

	sake dev/tests/module/framework,cms
	# Run all tests of a specific module (comma-separated)

	sake dev/tests/FolderTest,OtherTest
	# Run specific tests (comma-separated)

	sake dev/tests/all "flush=all&foo=bar"
	# Run tests with optional `$_GET` parameters

	sake dev/tests/all SkipTests=MySkippedTest
	# Skip some tests

```
A major impedement to testing is that by default tests are extremely slow to run.  There are two things that can be done to speed them up:

### Disable xDebug
Unless executing a coverage report there is no need to have xDebug enabled.

```bash
    # Disable xdebug
    sudo php5dismod xdebug
    
    # Run tests
    phpunit framework/tests/
    
    # Enable xdebug
    sudo php5enmod xdebug
    
```
### Use SQLite In Memory
SQLIte can be configured to run in memory as opposed to disk and this makes testing an order of magnitude faster.  To effect this change add the following to mysite/_config.php - this enables an optional flag to switch between MySQL and SQLite.  Note also that the package silverstripe/sqlite3 will need installed, version will vary depending on which version of SilverStripe is being tested.

```php
    if(Director::isDev()) {
    	if(isset($_GET['db']) && ($db = $_GET['db'])) {
        	global $databaseConfig;
        	if($db == 'sqlite3') {
        		$databaseConfig['type'] = 'SQLite3Database';
        		$databaseConfig['path'] = ':memory:';
        	}
    	}
	}

```

```bash
    phpunit framework/tests '' db=sqlite3
    
```
### Speed Comparison
Testing against a medium sized module with 93 tests:
* SQLite - 16.15s
* MySQL - 314s
This means using SQLite will run tests over 20 times faster.

## Test Databases and Fixtures

SilverStripe tests create their own database when the test starts. New `ss_tmp` databases are created using the same 
connection details you provide for the main website. The new `ss_tmp` database does not copy what is currently in your 
application database. To provide seed data use a [Fixture](fixtures) file.

[alert]
As the test runner will create new databases for the tests to run, the database user should have the appropriate 
permissions to create new databases on your server.
[/alert]

[notice]
The test database is rebuilt every time one of the test methods is run. Over time, you may have several hundred test 
databases on your machine. To get rid of them is a call to `http://yoursite.com/dev/tests/cleanupdb`
[/notice]

## Custom PHPUnit Configuration

The `phpunit` executable can be configured by command line arguments or through an XML file. SilverStripe comes with a 
default `phpunit.xml.dist` that you can use as a starting point. Copy the file into `phpunit.xml` and customise to your 
needs.

**phpunit.xml**

```xml
	<phpunit bootstrap="framework/tests/bootstrap.php" colors="true">
		<testsuite name="Default">
			<directory>mysite/tests</directory>
			<directory>cms/tests</directory>
			<directory>framework/tests</directory>
		</testsuite>
		
		<listeners>
			<listener class="SS_TestListener" file="framework/dev/TestListener.php" />
		</listeners>
		
		<groups>
			<exclude>
				<group>sanitychecks</group>
			</exclude>
		</groups>
	</phpunit>

```
This configuration file doesn't apply for running tests through the "sake" wrapper
[/alert]


### setUp() and tearDown()

In addition to loading data through a [Fixture File](fixtures), a test case may require some additional setup work to be
run before each test method. For this, use the PHPUnit `setUp` and `tearDown` methods. These are run at the start and 
end of each test.

```php
	<?php

	class PageTest extends SapphireTest {

		function setUp() {
			parent::setUp();

			// create 100 pages
			for($i=0; $i<100; $i++) {
				$page = new Page(array('Title' => "Page $i"));
				$page->write();
				$page->publish('Stage', 'Live');
			}

			// set custom configuration for the test.
			Config::inst()->update('Foo', 'bar', 'Hello!');
		}

		public function testMyMethod() {
			// ..
		}

		public function testMySecondMethod() {
			// ..
		}
	}

```
individual test case.

```php
	<?php

	class PageTest extends SapphireTest {

		function setUpOnce() {
			parent::setUpOnce();

			// ..
		}

		public function tearDownOnce() {
			parent::tearDownOnce();

			// ..
		}
	}
	
```
### Config and Injector Nesting

A powerful feature of both [`Config`](/developer_guides/configuration/configuration/) and [`Injector`](/developer_guides/extending/injector/) is the ability to "nest" them so that you can make changes that can easily be discarded without having to manage previous values.

The testing suite makes use of this to "sandbox" each of the unit tests as well as each suite to prevent leakage between tests.

If you need to make changes to `Config` (or `Injector) for each test (or the whole suite) you can safely update `Config` (or `Injector`) settings in the `setUp` or `tearDown` functions.

It's important to remember that the `parent::setUp();` functions will need to be called first to ensure the nesting feature works as expected.

```php
	function setUpOnce() {
		parent::setUpOnce();
		//this will remain for the whole suite and be removed for any other tests
		Config::inst()->update('ClassName', 'var_name', 'var_value');
	}
	
	function testFeatureDoesAsExpected() {
		//this will be reset to 'var_value' at the end of this test function
		Config::inst()->update('ClassName', 'var_name', 'new_var_value');
	}
	
	function testAnotherFeatureDoesAsExpected() {
		Config::inst()->get('ClassName', 'var_name'); // this will be 'var_value'
	}

```

PHPUnit can generate a code coverage report ([docs](http://www.phpunit.de/manual/current/en/code-coverage-analysis.html))
by executing the following commands.

```bash
	phpunit --coverage-html assets/coverage-report
	# Generate coverage report for the whole project

```
 	# Generate coverage report for the "mysite" module

[notice]
These commands will output a report to the `assets/coverage-report/` folder. To view the report, open the `index.html`
file within a web browser.
[/notice]

Typically, only your own custom PHP code in your project should be regarded when producing these reports. To exclude 
some `thirdparty/` directories add the following to the `phpunit.xml` configuration file.

```xml
	<filter>
		<blacklist>
			<directory suffix=".php">framework/dev/</directory>
			<directory suffix=".php">framework/thirdparty/</directory>
			<directory suffix=".php">cms/thirdparty/</directory>
			
			<!-- Add your custom rules here -->
			<directory suffix=".php">mysite/thirdparty/</directory>
		</blacklist>
	</filter>

```

* [How to Write a SapphireTest](how_tos/write_a_sapphiretest)
* [How to Write a FunctionalTest](how_tos/write_a_functionaltest)
* [Fixtures](fixtures)

## API Documentation

* [api:TestRunner]
* [api:SapphireTest]
* [api:FunctionalTest]
