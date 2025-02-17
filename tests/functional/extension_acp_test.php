<?php
/**
*
* This file is part of the phpBB Forum Software package.
*
* @copyright (c) phpBB Limited <https://www.phpbb.com>
* @license GNU General Public License, version 2 (GPL-2.0)
*
* For full copyright and license information, please see
* the docs/CREDITS.txt file.
*
*/

/**
* @group functional
*/
class phpbb_functional_extension_acp_test extends phpbb_functional_test_case
{
	private static $helper;

	protected static $fixtures = array(
		'./',
	);

	static public function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$helper = new phpbb_test_case_helpers(__CLASS__);
		self::$helper->copy_ext_fixtures(__DIR__ . '/../extension/ext/', self::$fixtures);
	}

	static public function tearDownAfterClass(): void
	{
		parent::tearDownAfterClass();

		self::$helper->restore_original_ext_dir();
	}

	protected function setUp(): void
	{
		parent::setUp();

		$this->purge_cache();

		// Clear the phpbb_ext table
		$this->db->sql_query('DELETE FROM phpbb_ext');

		// Insert our base data
		$insert_rows = array(
			array(
				'ext_name'		=> 'vendor2/foo',
				'ext_active'	=> true,
				'ext_state'		=> 'b:0;',
			),
			array(
				'ext_name'		=> 'vendor/moo',
				'ext_active'	=> false,
				'ext_state'		=> 'b:0;',
			),

			// do not exist
			array(
				'ext_name'		=> 'vendor/test2',
				'ext_active'	=> true,
				'ext_state'		=> 'b:0;',
			),
			array(
				'ext_name'		=> 'vendor/test3',
				'ext_active'	=> false,
				'ext_state'		=> 'b:0;',
			),
		);
		$this->db->sql_multi_insert('phpbb_ext', $insert_rows);

		$this->login();
		$this->admin_login();

		$this->add_lang('acp/extensions');
	}

	public function test_list()
	{
		$crawler = self::request('GET', 'adm/index.php?i=acp_extensions&mode=main&sid=' . $this->sid);

		$this->assertCount(1, $crawler->filter('.ext_enabled'));
		$this->assertCount(7, $crawler->filter('.ext_disabled'));

		$this->assertStringContainsString('phpBB Foo Extension', $crawler->filter('.ext_enabled')->eq(0)->text());
		$this->assertContainsLang('EXTENSION_DISABLE', $crawler->filter('.ext_enabled')->eq(0)->text());

		$this->assertStringContainsString('phpBB Moo Extension', $crawler->filter('.ext_disabled')->eq(2)->text());
		$this->assertContainsLang('DETAILS', $crawler->filter('.ext_disabled')->eq(2)->text());
		$this->assertContainsLang('EXTENSION_ENABLE', $crawler->filter('.ext_disabled')->eq(2)->text());
		$this->assertContainsLang('EXTENSION_DELETE_DATA', $crawler->filter('.ext_disabled')->eq(2)->text());

		$this->assertStringContainsString('The “vendor/test2” extension is not valid.', $crawler->filter('.ext_disabled')->eq(0)->text());

		$this->assertStringContainsString('The “vendor/test3” extension is not valid.', $crawler->filter('.ext_disabled')->eq(1)->text());

		$this->assertStringContainsString('phpBB Bar Extension', $crawler->filter('.ext_disabled')->eq(3)->text());
		$this->assertContainsLang('DETAILS', $crawler->filter('.ext_disabled')->eq(3)->text());
		$this->assertContainsLang('EXTENSION_ENABLE', $crawler->filter('.ext_disabled')->eq(3)->text());

		// Check that invalid extensions are not listed.
		$this->assertStringNotContainsString('phpBB BarFoo Extension', $crawler->filter('.table1')->text());
		$this->assertStringNotContainsString('barfoo', $crawler->filter('.table1')->text());

		$this->assertStringNotContainsString('vendor3/bar', $crawler->filter('.table1')->text());
	}

	public function test_details()
	{
		$crawler = self::request('GET', 'adm/index.php?i=acp_extensions&mode=main&action=details&ext_name=vendor2%2Ffoo&sid=' . $this->sid);

		$validation = array(
			'DISPLAY_NAME'		=> 'phpBB Foo Extension',
			'CLEAN_NAME'		=> 'vendor2/foo',
			'TYPE'				=> 'phpbb-extension',
			'DESCRIPTION'		=> 'An example/sample extension to be used for testing purposes in phpBB Development.',
			'VERSION'	  		=> '1.0.0',
			'TIME'				=> '2012-02-15 01:01:01',
			'LICENSE'			=> 'GPL-2.0',
			'PHPBB_VERSION'		=> '3.1.*@dev',
			'PHP_VERSION'		=> '>=5.3',
			'AUTHOR_NAME'		=> 'John Smith',
			'AUTHOR_EMAIL'		=> 'email@phpbb.com',
			'AUTHOR_HOMEPAGE'	=> 'http://phpbb.com',
			'AUTHOR_ROLE'		=> 'N/A',
		);

		for ($i = 0; $i < $crawler->filter('dl')->count(); $i++)
		{
			$text = trim($crawler->filter('dl')->eq($i)->text());

			$match = false;

			foreach ($validation as $language_key => $expected)
			{
				if (strpos($text, $this->lang($language_key)) === 0)
				{
					$match = true;

					$this->assertStringContainsString($expected, $text);
				}
			}

			if (!$match)
			{
				$this->fail('Unexpected field: "' . $text . '"');
			}
		}
	}

	public function test_enable_pre()
	{
		// Foo is already enabled (redirect to list)
		$crawler = self::request('GET', 'adm/index.php?i=acp_extensions&mode=main&action=enable_pre&ext_name=vendor2%2Ffoo&sid=' . $this->sid);
		$this->assertContainsLang('EXTENSION_NAME', $crawler->filter('div.main thead')->text());
		$this->assertContainsLang('EXTENSION_OPTIONS', $crawler->filter('div.main thead')->text());
		$this->assertContainsLang('EXTENSION_ACTIONS', $crawler->filter('div.main thead')->text());

		$crawler = self::request('GET', 'adm/index.php?i=acp_extensions&mode=main&action=enable_pre&ext_name=vendor%2Fmoo&sid=' . $this->sid);
		$this->assertStringContainsString($this->lang('EXTENSION_ENABLE_CONFIRM', 'phpBB Moo Extension'), $crawler->filter('#main')->text());

		// Correctly submit the enable form, default not enableable message
		$crawler = self::request('GET', 'adm/index.php?i=acp_extensions&mode=main&action=enable_pre&ext_name=vendor3%2Ffoo&sid=' . $this->sid);
		$this->assertContainsLang('EXTENSION_NOT_ENABLEABLE', $crawler->filter('.errorbox')->text());

		// Custom reason messages returned by not enableable extension
		$crawler = self::request('GET', 'adm/index.php?i=acp_extensions&mode=main&action=enable_pre&ext_name=vendor5%2Ffoo&sid=' . $this->sid);
		$this->assertStringContainsString('Reason 1', $crawler->filter('.errorbox')->text());
		$this->assertStringContainsString('Reason 2', $crawler->filter('.errorbox')->text());
	}

	public function test_disable_pre()
	{
		// Moo is not enabled (redirect to list)
		$crawler = self::request('GET', 'adm/index.php?i=acp_extensions&mode=main&action=disable_pre&ext_name=vendor%2Fmoo&sid=' . $this->sid);
		$this->assertContainsLang('EXTENSION_NAME', $crawler->filter('div.main thead')->text());
		$this->assertContainsLang('EXTENSION_OPTIONS', $crawler->filter('div.main thead')->text());
		$this->assertContainsLang('EXTENSION_ACTIONS', $crawler->filter('div.main thead')->text());

		$crawler = self::request('GET', 'adm/index.php?i=acp_extensions&mode=main&action=disable_pre&ext_name=vendor2%2Ffoo&sid=' . $this->sid);
		$this->assertStringContainsString($this->lang('EXTENSION_DISABLE_CONFIRM', 'phpBB Foo Extension'), $crawler->filter('#main')->text());
	}

	public function test_delete_data_pre()
	{
		// test2 is not available (error)
		$crawler = self::request('GET', 'adm/index.php?i=acp_extensions&mode=main&action=delete_data_pre&ext_name=test2&sid=' . $this->sid);
		$this->assertStringContainsString($this->lang('FILE_NOT_FOUND', ''), $crawler->filter('.errorbox')->text());

		// foo is not disabled (redirect to list)
		$crawler = self::request('GET', 'adm/index.php?i=acp_extensions&mode=main&action=delete_data_pre&ext_name=vendor2%2Ffoo&sid=' . $this->sid);
		$this->assertContainsLang('EXTENSION_NAME', $crawler->filter('div.main thead')->text());
		$this->assertContainsLang('EXTENSION_OPTIONS', $crawler->filter('div.main thead')->text());
		$this->assertContainsLang('EXTENSION_ACTIONS', $crawler->filter('div.main thead')->text());

		$crawler = self::request('GET', 'adm/index.php?i=acp_extensions&mode=main&action=delete_data_pre&ext_name=vendor%2Fmoo&sid=' . $this->sid);
		$this->assertStringContainsString('Are you sure that you wish to delete the data associated with “phpBB Moo Extension”?', $crawler->filter('.errorbox')->text());
	}

	public function test_actions()
	{
		// Access enable page without hash
		$crawler = self::request('GET', 'adm/index.php?i=acp_extensions&mode=main&action=enable&ext_name=vendor%2Fmoo&sid=' . $this->sid);
		$this->assertContainsLang('FORM_INVALID', $crawler->filter('.errorbox')->text());

		// Correctly submit the enable form
		$crawler = self::request('GET', 'adm/index.php?i=acp_extensions&mode=main&action=enable_pre&ext_name=vendor%2Fmoo&sid=' . $this->sid);
		$form = $crawler->selectButton('enable')->form();
		$crawler = self::submit($form);
		$this->assertContainsLang('EXTENSION_ENABLE_SUCCESS', $crawler->filter('.successbox')->text());

		// Access disable page without hash
		$crawler = self::request('GET', 'adm/index.php?i=acp_extensions&mode=main&action=disable&ext_name=vendor%2Fmoo&sid=' . $this->sid);
		$this->assertContainsLang('FORM_INVALID', $crawler->filter('.errorbox')->text());

		// Correctly submit the disable form
		$crawler = self::request('GET', 'adm/index.php?i=acp_extensions&mode=main&action=disable_pre&ext_name=vendor%2Fmoo&sid=' . $this->sid);
		$form = $crawler->selectButton('disable')->form();
		$crawler = self::submit($form);
		$this->assertContainsLang('EXTENSION_DISABLE_SUCCESS', $crawler->filter('.successbox')->text());

		// Access delete_data page without hash
		$crawler = self::request('GET', 'adm/index.php?i=acp_extensions&mode=main&action=delete_data&ext_name=vendor%2Fmoo&sid=' . $this->sid);
		$this->assertContainsLang('FORM_INVALID', $crawler->filter('.errorbox')->text());

		// Correctly submit the delete data form
		$crawler = self::request('GET', 'adm/index.php?i=acp_extensions&mode=main&action=delete_data_pre&ext_name=vendor%2Fmoo&sid=' . $this->sid);
		$form = $crawler->selectButton('delete_data')->form();
		$crawler = self::submit($form);
		$this->assertContainsLang('EXTENSION_DELETE_DATA_SUCCESS', $crawler->filter('.successbox')->text());

		// Attempt to enable invalid extension
		$crawler = self::request('GET', 'adm/index.php?i=acp_extensions&mode=main&action=enable_pre&ext_name=barfoo&sid=' . $this->sid);
		$this->assertContainsLang('EXTENSION_DIR_INVALID', $crawler->filter('.errorbox')->text());

		// Test installing/uninstalling extension altogether
		$this->logout();
		$this->install_ext('vendor/moo');
		$this->uninstall_ext('vendor/moo');
	}

	public function test_extensions_catalog()
	{
		// Access extensions catalog main page
		$crawler = self::request('GET', 'adm/index.php?i=acp_extensions&mode=catalog&sid=' . $this->sid);
		$this->assertContainsLang('ACP_EXTENSIONS_CATALOG', $this->get_content());

		$this->assertContainsLang('BROWSE_EXTENSIONS_DATABASE', $crawler->filter('fieldset[class="quick quick-left"] > span > a')->eq(0)->text());
		$this->assertContainsLang('SETTINGS', $crawler->filter('fieldset[class="quick quick-left"] > span > a')->eq(1)->text());

		$form = $crawler->selectButton('Submit')->form();
		$form['minimum_stability']->select('dev');
		$crawler = self::submit($form);
		$this->assertContainsLang('CONFIG_UPDATED', $crawler->filter('div[class="successbox"] > p')->text());

		// Revisit extensions catalog main page after configuration change
		$crawler = self::request('GET', 'adm/index.php?i=acp_extensions&mode=catalog&sid=' . $this->sid);
		$this->assertContainsLang('ACP_EXTENSIONS_CATALOG', $this->get_content());

		// Ensure catalog has any records in extensions list
		$this->assertGreaterThan(0, $crawler->filter('tbody > tr > td > strong')->count());
	}
}
