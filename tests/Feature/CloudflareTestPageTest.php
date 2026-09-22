<?php namespace Tests\Feature;

use Tests\TestCase;

/**
 * The admin page under Tools > Checks and Tests dumps whichever Cloudflare headers reached the
 * server on that request. It exists to tell a Cloudflare configuration problem from an add-on
 * problem, and since 3.6.0 it is the only place continent is displayed.
 *
 * Request headers are passed through dispatch(), which needs framework 5.2 or later.
 */
class CloudflareTestPageTest extends TestCase
{
	const ROUTE = 'tools/hampel-aqp-show-cf-location';

	protected function setUp(): void
	{
		parent::setUp();

		$this->fakesErrors();
	}

	public function test_an_admin_with_the_option_permission_sees_the_headers_that_arrived()
	{
		$this->actingAsAdmin(['option' => true]);

		$reply = $this->dispatch(self::ROUTE, 'admin', [], [
			'HTTP_CF_IPCITY'      => 'Sydney',
			'HTTP_CF_IPCOUNTRY'   => 'AU',
			'HTTP_CF_IPCONTINENT' => 'OC',
		]);

		$this->assertReplyIsView($reply);
		$this->assertReplyTemplate($reply, 'hampel_aqp_tools_test_cloudflare');

		$location = $this->replyParam($reply, 'location');
		foreach (['city', 'country', 'continent_code', 'continent'] as $key)
		{
			$this->assertArrayHasKey($key, $location, "the page received no '{$key}' from the headers sent");
		}
		$this->assertSame('Sydney', $location['city']);
		$this->assertSame('Australia', $location['country']);
		$this->assertSame('OC', $location['continent_code']);
		$this->assertSame('Oceania', $location['continent']);
	}

	public function test_with_no_cloudflare_headers_the_page_renders_with_nothing_to_show()
	{
		$this->actingAsAdmin(['option' => true]);

		$reply = $this->dispatch(self::ROUTE, 'admin');

		$this->assertReplyTemplate($reply, 'hampel_aqp_tools_test_cloudflare');
		$this->assertSame([], $this->replyParam($reply, 'location'));
	}

	/**
	 * The `option` admin permission check arrived in 3.5.2; before it, any admin could open the
	 * page. A 403 is specific enough here - a broken route would not answer with one.
	 */
	public function test_an_admin_without_the_option_permission_is_refused()
	{
		$this->actingAsAdmin([]);

		$reply = $this->dispatch(self::ROUTE, 'admin');

		$this->assertReplyIsError($reply, 403);
	}

	protected function actingAsAdmin(array $adminPermissions): void
	{
		$admin = $this->actingAsMember(['is_admin' => true]);
		$this->setVisitorAdminPermissions($admin, $adminPermissions);
	}
}
