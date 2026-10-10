<?php namespace Hampel\ApprovalQueuePlus\Cli\Command;

use Hampel\ApprovalQueuePlus\Cli\RendersReport;
use Hampel\ApprovalQueuePlus\Data\CountryCodes;
use Hampel\ApprovalQueuePlus\Option\UserDataCleanUp;
use Hampel\ApprovalQueuePlus\Repository\UserData as UserDataRepo;
use Hampel\ApprovalQueuePlus\SubContainer\Cloudflare;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Whether this forum can actually do what approval-queue-plus:config says it is set up to do: the
 * table is there, the display still applies to the queue's template, the Cloudflare header map
 * still produces a location, and the clean-up will run when it says it will.
 *
 * Exit code: 1 if any check failed, 0 otherwise - a warning does not fail, so a gate can rely on
 * it. --strict makes a warning exit 2, for a monitor that wants to hear of one.
 *
 * **Nothing here writes, deletes or sends.** This add-on has no outbound anything, and the one
 * destructive thing it owns - the prune - is counted rather than run. So --unattended is accepted
 * for a uniform monitor command line and changes nothing; there are no sends to skip.
 *
 * The Cloudflare check is the one that earns its place. It runs the real header map against a
 * synthetic request carrying all ten headers as Cloudflare spells them, which is the only thing
 * short of a live zone that can catch a misspelt header name - and one went unnoticed through
 * several releases, because a CLI request and a development forum both record nothing either way.
 */
class Validate extends Command
{
	/** Every header Cloudflare's "Add visitor location headers" managed transform can send. */
	private const CLOUDFLARE_HEADERS = [
		'HTTP_CF_IPCITY' => 'Sydney',
		'HTTP_CF_IPCOUNTRY' => 'AU',
		'HTTP_CF_IPCONTINENT' => 'OC',
		'HTTP_CF_IPLONGITUDE' => '151.2093',
		'HTTP_CF_IPLATITUDE' => '-33.8688',
		'HTTP_CF_REGION' => 'New South Wales',
		'HTTP_CF_REGION_CODE' => 'NSW',
		'HTTP_CF_METRO_CODE' => '',
		'HTTP_CF_POSTAL_CODE' => '2000',
		'HTTP_CF_TIMEZONE' => 'Australia/Sydney',
	];

	/** The four modifications that are the whole of this add-on's display, and what each is for. */
	private const MODIFICATIONS = [
		'approvalQueuePlusRemoveTopInfo' => 'empties the queue item header',
		'approvalQueuePlusApprovalItemUser' => 'swaps in this add-on\'s macro',
		'approvalQueuePlusCss' => 'appends the queue styling',
		'approvalQueuePlusPageContainerReverse' => 'rewrites the queue link',
	];

	use RendersReport;

	protected function configure()
	{
		$this
			->setName('approval-queue-plus:validate')
			->setDescription('Check that the queue display, the Cloudflare map and the clean-up all work')
			->addOption('unattended', null, InputOption::VALUE_NONE,
				'Accepted for consistency with other validate commands - nothing here sends, so it changes nothing')
			->addOption('strict', null, InputOption::VALUE_NONE,
				'Exit 2 when there are warnings and no failures, instead of 0');
	}

	protected function errorLogPrefix(): string
	{
		return 'ApprovalQueuePlus: ';
	}

	protected function execute(InputInterface $input, OutputInterface $output)
	{
		$this->report = $output;

		$this->checkSection('Environment');
		$this->probe('environment', function ()
		{
			$this->environment();
		});

		$this->checkSection('Queue display');
		$this->probe('queue display', function ()
		{
			$this->display();
		});

		$this->checkSection('Cloudflare');
		$this->probe('cloudflare', function ()
		{
			$this->cloudflare();
		});

		$this->checkSection('Clean-up');
		$this->probe('clean-up', function ()
		{
			$this->cleanUp();
		});

		$this->report->writeln('');
		if ($this->checksFailed())
		{
			$this->report->writeln('<error>Validation failed - the Approval Queue will not show or keep what it is configured to</error>');
		}
		else
		{
			$this->report->writeln($this->checksWarned() ? '<comment>Validated, with warnings</comment>' : '<info>Validated</info>');
		}

		return $this->checkExitCode((bool) $input->getOption('strict'));
	}

	private function environment(): void
	{
		$app = \XF::app();

		$addOn = $app->addOnManager()->getById('Hampel/ApprovalQueuePlus');
		$required = $addOn ? ($addOn->getJson()['require']['XF'][1] ?? '') : '';
		$this->checkOk('XenForo', \XF::$version . ($required === '' ? '' : " - this add-on needs {$required}"));

		if ($app->db()->getSchemaManager()->tableExists('xf_aqp_user_data'))
		{
			$this->checkOk('table', 'xf_aqp_user_data');
		}
		else
		{
			$this->checkFail('table', 'xf_aqp_user_data is missing - nothing can be recorded or shown');
		}

		$permission = $app->db()->fetchRow(
			'SELECT permission_group_id, permission_id FROM xf_permission WHERE permission_group_id = ? AND permission_id = ?',
			['general', 'hampelAqpViewUserAgents']
		);

		if ($permission)
		{
			$this->checkOk('permission', 'general.hampelAqpViewUserAgents');
		}
		else
		{
			$this->checkFail('permission', 'general.hampelAqpViewUserAgents is missing - no moderator can see a user agent');
		}

		// 3.6.0 renamed this permission and migrated the grants. A row under the old id means the
		// rename did not run, and any grant still on it is doing nothing.
		$orphanedGrants = (int) $app->db()->fetchOne(
			'SELECT COUNT(*) FROM xf_permission_entry WHERE permission_group_id = ? AND permission_id = ?',
			['general', 'viewUserAgents']
		);

		if ($orphanedGrants)
		{
			$this->checkWarn('old permission', "{$orphanedGrants} grant(s) remain under the pre-3.6.0 id general.viewUserAgents and have no effect");
		}
	}

	private function display(): void
	{
		$app = \XF::app();
		$db = $app->db();

		foreach (['approval_queue_plus_user_macros' => 'public', 'hampel_aqp_tools_test_cloudflare' => 'admin'] AS $title => $type)
		{
			$exists = $db->fetchOne(
				'SELECT template_id FROM xf_template WHERE style_id = 0 AND type = ? AND title = ?',
				[$type, $title]
			);

			$exists
				? $this->checkOk("template {$type}", $title)
				: $this->checkFail("template {$type}", "{$title} is missing");
		}

		$rows = $db->fetchAllKeyed(
			'SELECT m.modification_key, m.enabled, COUNT(l.modification_id) AS logs,
				COALESCE(SUM(l.apply_count), 0) AS applied,
				GROUP_CONCAT(DISTINCT l.status) AS statuses
			FROM xf_template_modification AS m
			LEFT JOIN xf_template_modification_log AS l ON (l.modification_id = m.modification_id)
			WHERE m.addon_id = ?
			GROUP BY m.modification_id',
			'modification_key',
			['Hampel/ApprovalQueuePlus']
		);

		foreach (self::MODIFICATIONS AS $key => $purpose)
		{
			$label = substr($key, strlen('approvalQueuePlus'));

			if (!isset($rows[$key]))
			{
				$this->checkFail($label, "not registered - {$purpose}");
				continue;
			}

			$row = $rows[$key];

			if (!$row['enabled'])
			{
				$this->checkWarn($label, "disabled - {$purpose}");
			}
			elseif (!$row['logs'])
			{
				$this->checkWarn($label, 'never compiled against a template, so nothing has tried to apply it yet');
			}
			elseif ((int) $row['applied'] === 0)
			{
				$this->checkFail($label, "matched nothing - {$purpose}, and a XenForo upgrade is the usual cause");
			}
			else
			{
				$this->checkOk($label, 'applies ' . $row['applied'] . ' time(s)');
			}
		}
	}

	private function cloudflare(): void
	{
		$app = \XF::app();
		$container = $app->container();

		if (!isset($container['aqp.cloudflare']) || !($app->container('aqp.cloudflare') instanceof Cloudflare))
		{
			$this->checkFail('container', 'aqp.cloudflare is not registered - nothing records a location');
			return;
		}

		/** @var Cloudflare $cloudflare */
		$cloudflare = $app->container('aqp.cloudflare');
		$this->checkOk('container', 'aqp.cloudflare');

		$location = $this->locationFromSyntheticRequest($app, $cloudflare);

		// ten headers in, and two of them also produce a name: country and continent
		$expected = count(array_filter(self::CLOUDFLARE_HEADERS, 'strlen')) + 2;

		if (count($location) < $expected)
		{
			$missing = array_diff(['city', 'country_code', 'country', 'continent_code', 'continent',
				'longitude', 'latitude', 'region', 'region_code', 'postal_code', 'timezone'], array_keys($location));

			$this->checkFail('header map', 'a synthetic Cloudflare request produced ' . count($location)
				. " of {$expected} values - missing " . implode(', ', $missing));
		}
		else
		{
			$this->checkOk('header map', count($location) . " values from Cloudflare's headers");
		}

		$country = $location['country'] ?? '';
		$continent = $location['continent'] ?? '';

		($country === 'Australia' && $continent === 'Oceania')
			? $this->checkOk('code lookup', "AU resolves to {$country}, OC to {$continent}")
			: $this->checkFail('code lookup', "AU resolved to '{$country}' and OC to '{$continent}'");

		$rows = (int) $app->db()->fetchOne('SELECT COUNT(*) FROM xf_aqp_user_data');
		$located = (int) $app->db()->fetchOne(
			"SELECT COUNT(*) FROM xf_aqp_user_data WHERE cf_location NOT IN ('[]', '{}', '')"
		);

		if ($rows === 0)
		{
			$this->checkSkip('real arrivals', 'no registrations recorded yet');
		}
		elseif ($located === 0)
		{
			$this->checkWarn('real arrivals', "none of {$rows} recorded registrations carry a location - the"
				. ' Cloudflare managed transform "Add visitor location headers" is the usual cause');
		}
		else
		{
			$this->checkOk('real arrivals', "{$located} of {$rows} recorded registrations carry a location");
		}
	}

	/**
	 * Run the real header map against a request carrying every Cloudflare header, by swapping the
	 * application's request for one of ours and putting the original back. getCloudflareLocation()
	 * reads \XF::app()->request() rather than taking one, so this is the only way in; the swap is
	 * restored even when the map throws.
	 */
	private function locationFromSyntheticRequest(\XF\App $app, Cloudflare $cloudflare): array
	{
		$container = $app->container();
		$original = $container['request'];

		$container['request'] = new \XF\Http\Request(
			$app->inputFilterer(),
			[],
			[],
			[],
			self::CLOUDFLARE_HEADERS + ['REQUEST_METHOD' => 'GET']
		);

		try
		{
			return $cloudflare->getCloudflareLocation();
		}
		finally
		{
			$container['request'] = $original;
		}
	}

	private function cleanUp(): void
	{
		$app = \XF::app();
		$enabled = UserDataCleanUp::isEnabled();
		$delay = UserDataCleanUp::getDelay();

		if (!$enabled)
		{
			// nothing is broken, and recorded user agents and IP addresses are kept indefinitely
			$this->checkWarn('enabled', 'the clean-up is switched off, so recorded data is kept for ever');
		}
		else
		{
			$this->checkOk('enabled', 'yes');
		}

		if ($enabled && $delay < 1)
		{
			// the cut-off that produces is "now", so the cron declines rather than taking every row
			$this->checkFail('delay', 'the clean-up is on but its delay is missing or not a number, so'
				. ' the prune declines every run and nothing is ever cleaned up');
		}
		elseif ($enabled)
		{
			$this->checkOk('delay', $delay . ' day' . ($delay === 1 ? '' : 's'));
		}
		else
		{
			$this->checkSkip('delay', 'the clean-up is switched off');
		}

		$entry = $app->finder('XF:CronEntry')->whereId('approvalQueuePlusCleanup')->fetchOne();

		if (!$entry)
		{
			$this->checkFail('cron entry', 'approvalQueuePlusCleanup is missing, so nothing is scheduled');
		}
		elseif (!$entry->active)
		{
			$this->checkWarn('cron entry', 'approvalQueuePlusCleanup is inactive, so the prune never runs');
		}
		else
		{
			$this->checkOk('cron entry', 'next run ' . gmdate('Y-m-d H:i', $entry->next_run) . ' UTC');
		}

		if ($enabled && $delay >= 1)
		{
			/** @var UserDataRepo $repo */
			$repo = $app->repository('Hampel\ApprovalQueuePlus:UserData');

			// counted through the repository's own finder, so it cannot report different rows from
			// the ones the prune would delete. Counting runs the query; nothing is deleted.
			$this->checkOk('prune query', $repo->findPrunableUserData()->total()
				. ' row(s) eligible, registered on or before ' . gmdate('Y-m-d', $repo->getRegistrationCutoff()) . ' UTC');
		}
		else
		{
			$this->checkSkip('prune query', 'the prune would decline, so there is nothing to count');
		}
	}
}
