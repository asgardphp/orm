<?php
namespace Asgard\Orm\Tests;

class MigrationsTest extends \PHPUnit_Framework_TestCase {
	public function testAutoMigrate() {
		$app = new \Asgard\Core\App;
		$app['db'] = new \Asgard\Db\DB(array(
			'host' => 'localhost',
			'user' => 'root',
			'password' => '',
			'database' => 'asgard'
		));
		$app['config'] = new \Asgard\Core\Config;
		$app['hooks'] = new \Asgard\Hook\HooksManager;
		$app['cache'] = new \Asgard\Cache\NullCache;
		$app['entitiesManager'] = new \Asgard\Entity\EntitiesManager($app);
		\Asgard\Entity\Entity::setApp($app);

		$ormm = new \Asgard\Orm\ORMMigrations();
		$schema = new \Asgard\Db\Schema($app['db']);
		$schema->dropAll();

		$ormm->autoMigrate(array('Asgard\Orm\Tests\Fixtures\Post', 'Asgard\Orm\Tests\Fixtures\Category', 'Asgard\Orm\Tests\Fixtures\Author'), $schema);

		$tables = array();
		foreach($app['db']->query('SHOW TABLES')->all() as $v) {
			$table = array_values($v)[0];
			$tables[$table] = $app['db']->query('Describe `'.$table.'`')->all();
		}

		$this->assertEquals(
			array(
			  'author' =>
			  array(
			    array(
			      'Field' => 'id',
			      'Type' => 'int(11)',
			      'Null' => 'NO',
			      'Key' => 'PRI',
			      'Default' => NULL,
			      'Extra' => 'auto_increment',
			    ),
			    array(
			      'Field' => 'name',
			      'Type' => 'varchar(255)',
			      'Null' => 'YES',
			      'Key' => '',
			      'Default' => NULL,
			      'Extra' => '',
			    ),
			  ),
			  'category' =>
			  array (
			    array(
			      'Field' => 'id',
			      'Type' => 'int(11)',
			      'Null' => 'NO',
			      'Key' => 'PRI',
			      'Default' => NULL,
			      'Extra' => 'auto_increment',
			    ),
			    array(
			      'Field' => 'name',
			      'Type' => 'varchar(255)',
			      'Null' => 'YES',
			      'Key' => '',
			      'Default' => NULL,
			      'Extra' => '',
			    ),
			  ),
			  'post' =>
			  array(
			    array(
			      'Field' => 'id',
			      'Type' => 'int(11)',
			      'Null' => 'NO',
			      'Key' => 'PRI',
			      'Default' => NULL,
			      'Extra' => 'auto_increment',
			    ),
			    array(
			      'Field' => 'title',
			      'Type' => 'varchar(255)',
			      'Null' => 'NO',
			      'Key' => 'UNI',
			      'Default' => 'a',
			      'Extra' => '',
			    ),
			    array(
			      'Field' => 'posted',
			      'Type' => 'date',
			      'Null' => 'YES',
			      'Key' => '',
			      'Default' => NULL,
			      'Extra' => '',
			    ),
			    array(
			      'Field' => 'author_id',
			      'Type' => 'int(11)',
			      'Null' => 'YES',
			      'Key' => '',
			      'Default' => NULL,
			      'Extra' => '',
			    ),
			  ),
			  'post_translation' =>
			  array (
			    array(
			      'Field' => 'id',
			      'Type' => 'int(11)',
			      'Null' => 'NO',
			      'Key' => '',
			      'Default' => NULL,
			      'Extra' => '',
			    ),
			    array(
			      'Field' => 'locale',
			      'Type' => 'varchar(50)',
			      'Null' => 'NO',
			      'Key' => '',
			      'Default' => NULL,
			      'Extra' => '',
			    ),
			    array(
			      'Field' => 'content',
			      'Type' => 'text',
			      'Null' => 'YES',
			      'Key' => '',
			      'Default' => NULL,
			      'Extra' => '',
			    ),
			  ),
			  'category_post' =>
			  array (
			    array(
			      'Field' => 'post_id',
			      'Type' => 'int(11)',
			      'Null' => 'NO',
			      'Key' => '',
			      'Default' => NULL,
			      'Extra' => '',
			    ),
			    array(
			      'Field' => 'category_id',
			      'Type' => 'int(11)',
			      'Null' => 'NO',
			      'Key' => '',
			      'Default' => NULL,
			      'Extra' => '',
			    ),
			  ),
			),
			$tables
		);
	}

	public function testGenerateMigration() {
		\Asgard\Utils\FileManager::unlink(__DIR__.'/migrations/');
		$db = new \Asgard\Db\DB(array(
			'host' => 'localhost',
			'user' => 'root',
			'password' => '',
			'database' => 'asgard'
		));
		$schema = new \Asgard\Db\Schema($db);
		$schema->dropAll();

		$ormm = new \Asgard\Orm\ORMMigrations(new \Asgard\Migration\MigrationsManager(__DIR__.'/migrations/'));
		$ormm->generateMigration(array('Asgard\Orm\Tests\Fixtures\Post', 'Asgard\Orm\Tests\Fixtures\Author', 'Asgard\Orm\Tests\Fixtures\Category'), 'Post', $db);

		$this->assertRegExp('/\{'."\n".
'    "Post": \{'."\n".
'        "added": [0-9.]+'."\n".
'    \}'."\n".
'\}/', file_get_contents(__DIR__.'/migrations/migrations.json'));

		$this->assertFileEquals(__DIR__.'/Fixtures/migrations/Post.php', __DIR__.'/migrations/Post.php');
	}
}