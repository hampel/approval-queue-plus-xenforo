<?php namespace Tests\Feature;

use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Tests\TestCase;
use XF\Entity\User;
use XF\Http\Request;

/**
 * The registration service extension is the only writer of `xf_aqp_user_data`, so every row the
 * Approval Queue shows depends on this path. These tests register a real user through the real
 * service, inside a transaction that is rolled back afterwards.
 *
 * They need framework 5.0 or later. The extension is registered on the pre-2.3 class name
 * `XF\Service\User\Registration` so the add-on can support XF 2.2, and below 5.0 an isolated suite
 * did not apply it to 2.3's `RegistrationService` - these tests would have run XenForo's class and
 * failed for a reason that had nothing to do with the add-on.
 */
class RegistrationWritesUserDataTest extends TestCase
{
	use UsesDatabaseTransactions;

	protected function setUp(): void
	{
		parent::setUp();

		$this->fakesMail();
		$this->fakesErrors();

		// Register the way this add-on exists for: manual approval on and no email confirmation,
		// so the new user lands in the Approval Queue and nothing is sent.
		$this->setOption('registrationSetup', array_merge(\XF::options()->registrationSetup, [
			'moderation'        => true,
			'emailConfirmation' => false,
			'requireDob'        => false,
			'requireLocation'   => false,
		]));

		$this->relaxStrictModeForForeignColumns();
	}

	public function test_registration_records_the_user_agent_and_cloudflare_location()
	{
		$this->requestFrom([
			'HTTP_USER_AGENT'     => 'Mozilla/5.0 (registration test)',
			'REMOTE_ADDR'         => '203.0.113.9',
			'HTTP_CF_IPCOUNTRY'   => 'AU',
			'HTTP_CF_IPCONTINENT' => 'OC',
			'HTTP_CF_IPCITY'      => 'Sydney',
		]);

		$user = $this->register();

		$this->assertSame('moderated', $user->user_state, 'the user should be waiting in the queue');

		$row = $this->storedRowFor($user);
		$this->assertSame('Mozilla/5.0 (registration test)', $row['user_agent']);
		$this->assertSame('AU', $row['iso_code']);
		$this->assertSame('Sydney', $row['cf_location']['city']);
		$this->assertSame('Australia', $row['cf_location']['country']);
		$this->assertSame('OC', $row['cf_location']['continent_code']);
		$this->assertSame('Oceania', $row['cf_location']['continent']);
	}

	public function test_registration_without_cloudflare_still_records_the_user_agent()
	{
		$this->requestFrom([
			'HTTP_USER_AGENT' => 'Mozilla/5.0 (no proxy)',
			'REMOTE_ADDR'     => '203.0.113.10',
		]);

		$row = $this->storedRowFor($this->register());

		$this->assertSame('Mozilla/5.0 (no proxy)', $row['user_agent']);
		$this->assertSame('', $row['iso_code']);
		$this->assertSame([], $row['cf_location']);
	}

	protected function requestFrom(array $server): void
	{
		$server += ['REQUEST_METHOD' => 'POST'];

		$this->swap('request', new Request($this->app()->inputFilterer(), [], [], [], $server));
	}

	protected function register(): User
	{
		$suffix = bin2hex(random_bytes(4));

		$registration = $this->app()->service('XF:User\Registration');
		$registration->setFromInput([
			'username' => "aqptest{$suffix}",
			'email'    => "aqptest{$suffix}@example.com",
			'password' => "correct-horse-battery-staple-{$suffix}",
		]);
		$registration->skipEmailConfirmation();

		$errors = [];
		$this->assertTrue($registration->validate($errors),
			'registration did not validate: ' . implode('; ', array_map('strval', $errors)));

		return $registration->save();
	}

	/**
	 * Read the row back from the table rather than through the entity manager, which would hand
	 * back the instance the save created - so this asserts what was stored, not what was built.
	 */
	protected function storedRowFor(User $user): array
	{
		$row = $this->app()->db()->fetchRow(
			'SELECT * FROM xf_aqp_user_data WHERE user_id = ?', $user->user_id
		);

		$this->assertIsArray($row, "no xf_aqp_user_data row was written for user {$user->user_id}");

		$row['cf_location'] = json_decode($row['cf_location'], true);

		return $row;
	}

	/**
	 * Another add-on on a development forum may add a NOT NULL column with no default to a core
	 * table that registration writes. An isolated test application has not loaded that add-on,
	 * so XenForo leaves the column out of its INSERT and strict mode rejects the whole row
	 * before this add-on's code is reached. Dropping strict mode for this connection lets MySQL
	 * fill such a column with its implicit default instead. On a forum with no such column it
	 * changes nothing.
	 *
	 * It cannot hide a defect in this add-on: every value this add-on stores is asserted on
	 * above, and its entity supplies all four of its columns explicitly.
	 */
	protected function relaxStrictModeForForeignColumns(): void
	{
		$db = $this->app()->db();
		$original = $db->fetchOne('SELECT @@SESSION.sql_mode');

		$relaxed = implode(',', array_filter(explode(',', $original), function ($mode)
		{
			return !in_array($mode, ['STRICT_ALL_TABLES', 'STRICT_TRANS_TABLES'], true);
		}));

		$db->query('SET SESSION sql_mode = ' . $db->quote($relaxed));

		$this->beforeApplicationDestroyed(function () use ($db, $original)
		{
			$db->query('SET SESSION sql_mode = ' . $db->quote($original));
		});
	}
}
