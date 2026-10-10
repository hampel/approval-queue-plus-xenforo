<?php namespace Tests\Unit;

use Hampel\ApprovalQueuePlus\Listener;
use Tests\TestCase;

/**
 * `Listener::adminApiRedaction()` answers `hampel_admin_api_redaction`, which is how the Admin API
 * learns which of this add-on's settings are credentials. It declares none, because neither option
 * is one and the add-on reads no `config.php` keys.
 *
 * The declaration goes stale silently: nothing fails if an option is renamed or a credential is
 * added later, and the consequence is a value published to whoever can read the API. So these
 * tests check it against `_output/options/` rather than against itself.
 */
class SecretsDeclarationTest extends TestCase
{
	/**
	 * Option ids or `option.member` paths that read like a credential and are not one, with the
	 * reason each is safe. Empty while nothing here matches.
	 */
	private const NOT_SECRETS = [];

	private const CREDENTIAL = '/key|token|secret|password|webhook/i';

	public function test_it_declares_this_add_on_only()
	{
		// an entry for another add-on's id would be a claim about code this one has not read
		$this->assertSame(['Hampel/ApprovalQueuePlus'], array_keys($this->declarations()));
	}

	public function test_it_declares_no_secrets_and_no_config_keys()
	{
		$this->assertSame(['options' => [], 'config' => []], $this->declared());
	}

	/**
	 * Every assertion in these tests is outside its loop. With an empty declaration a loop over
	 * the declared paths runs zero times, and a test whose only assertions sit inside one asserts
	 * nothing while reporting as coverage - which `failOnRisky` turns into a failure, but only
	 * once there are no other assertions at all.
	 */
	public function test_every_declared_secret_is_an_option_this_add_on_has()
	{
		$problems = [];

		foreach ($this->declared()['options'] as $path)
		{
			$parts = explode('.', $path);
			$file = $this->addOnRoot() . "/_output/options/{$parts[0]}.json";

			if (!is_file($file))
			{
				$problems[] = "{$path}: no such option";
				continue;
			}

			$members = json_decode(file_get_contents($file), true)['sub_options'] ?? [];

			if (isset($parts[1]) && !in_array($parts[1], $members, true))
			{
				$problems[] = "{$path}: not a member of {$parts[0]}";
			}
		}

		$this->assertSame([], $problems);
	}

	/**
	 * The option ids are not where a credential usually hides - it sits as one member of an array
	 * option beside an on/off switch, under a name that matches nothing. So this walks the members
	 * too.
	 */
	public function test_an_option_or_member_that_reads_like_a_credential_is_declared_or_decided()
	{
		$decided = array_merge(self::NOT_SECRETS, $this->declared()['options']);
		$undecided = [];

		foreach ($this->optionIds() as $optionId)
		{
			if (!in_array($optionId, $decided, true) && preg_match(self::CREDENTIAL, $optionId))
			{
				$undecided[] = $optionId;
			}

			$file = $this->addOnRoot() . "/_output/options/{$optionId}.json";

			foreach (json_decode(file_get_contents($file), true)['sub_options'] ?? [] as $member)
			{
				$path = "{$optionId}.{$member}";

				if (preg_match(self::CREDENTIAL, $member) && !in_array($path, $decided, true))
				{
					$undecided[] = $path;
				}
			}
		}

		$this->assertSame([], $undecided, 'declare each as a secret, or add it to NOT_SECRETS');
	}

	/**
	 * A guard on the test above rather than on the add-on: with no options on disk it would pass
	 * having examined nothing, which is the shape that reads as coverage and is not.
	 */
	public function test_the_credential_sweep_has_options_to_examine()
	{
		$this->assertSame(
			['approvalQueuePlusDefaultOrder', 'approvalQueuePlusUserAgentCleanUp'],
			$this->optionIds()
		);
	}

	private function declarations(): array
	{
		$declarations = [];
		Listener::adminApiRedaction($declarations);

		return $declarations;
	}

	private function declared(): array
	{
		return $this->declarations()['Hampel/ApprovalQueuePlus'] + ['options' => [], 'config' => []];
	}

	private function optionIds(): array
	{
		$ids = [];

		foreach (glob($this->addOnRoot() . '/_output/options/*.json') ?: [] as $file)
		{
			if (basename($file) != '_metadata.json')
			{
				$ids[] = basename($file, '.json');
			}
		}

		sort($ids);

		return $ids;
	}

	private function addOnRoot(): string
	{
		return dirname(__DIR__, 2);
	}
}
