<?php namespace Hampel\ApprovalQueuePlus\Cli\Command;

use Hampel\ApprovalQueuePlus\Cli\RendersReport;
use Hampel\ApprovalQueuePlus\Option\UserDataCleanUp;
use Hampel\ApprovalQueuePlus\Repository\UserData as UserDataRepo;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * What this forum is configured to show in the Approval Queue, and what it has recorded. It reads
 * and prints; it writes nothing and deletes nothing. approval-queue-plus:validate is the command
 * that finds out whether any of it works.
 *
 * **Nothing this add-on stores or reads is a credential**, so there is nothing here to redact -
 * it holds no API key, no token and no config.php block, which is also why its Admin API secrets
 * declaration is empty. The user agents and IP addresses in `xf_aqp_user_data` are personal data
 * rather than secrets, and this command reports counts of them, never a row.
 */
class Config extends Command
{
	use RendersReport;

	protected function configure()
	{
		$this
			->setName('approval-queue-plus:config')
			->setDescription('Show what the Approval Queue is configured to show, and what has been recorded');
	}

	protected function errorLogPrefix(): string
	{
		return 'ApprovalQueuePlus: ';
	}

	protected function execute(InputInterface $input, OutputInterface $output)
	{
		$this->report = $output;
		$app = \XF::app();
		$db = $app->db();

		$this->heading('Approval Queue Plus');
		$addOn = $app->addOnManager()->getById('Hampel/ApprovalQueuePlus');
		$this->detail('add-on', $addOn ? (string) $addOn->getJson()['version_string'] : '');
		$this->detail('XenForo', \XF::$version);
		$this->detail('PHP', PHP_VERSION);

		$this->heading('Queue display');
		$order = (string) (\XF::options()->approvalQueuePlusDefaultOrder ?? '');
		$this->detail('default order', ($order === 'desc' ? 'newest first' : 'oldest first')
			. ' ' . $this->muted('(options)')
			. ($order === 'desc' ? ' - the queue link carries order and direction' : ''));
		$this->detail('user agent row', 'shown with the general.hampelAqpViewUserAgents permission');
		$this->detail('email row', 'shown to a moderator who can bypass user privacy');
		$this->detail('ip row', 'shown to a moderator who can view IPs');
		$this->detail('modifications', $this->modificationSummary($db));

		$this->heading('Recorded data');
		$rows = (int) $db->fetchOne('SELECT COUNT(*) FROM xf_aqp_user_data');
		$this->detail('table', 'xf_aqp_user_data');
		$this->detail('rows', (string) $rows);

		if ($rows === 0)
		{
			// a count of nothing is not an unset setting, so it does not read as `not set`
			$this->detail('with a user agent', 'no rows recorded yet');
			$this->detail('with a location', 'no rows recorded yet');
		}
		else
		{
			$agents = (int) $db->fetchOne("SELECT COUNT(*) FROM xf_aqp_user_data WHERE user_agent <> ''");
			$located = $this->withLocation($db);

			$this->detail('with a user agent', "{$agents} of {$rows}");
			$this->detail('with a location', "{$located} of {$rows}"
				. ($located === 0 ? ' - no Cloudflare headers have ever arrived' : ''));
		}

		$this->heading('Clean-up');
		$delay = UserDataCleanUp::getDelay();
		$this->detail('enabled', (UserDataCleanUp::isEnabled() ? 'yes' : 'no') . ' ' . $this->muted('(options)'));
		$this->detail('delay', ($delay < 1 ? '' : $delay . ' day' . ($delay === 1 ? '' : 's') . ' ' . $this->muted('(options)')));
		$this->detail('schedule', $this->schedule($app));
		$this->detail('next run', $this->nextRun($app));
		$this->detail('eligible now', $this->eligible($app, $delay));

		$this->heading('Cloudflare');
		$headers = $this->headerCount($app);
		$this->detail('headers mapped', $headers === null ? '' : (string) $headers);
		$this->detail('on this request', 'none - a CLI request never comes through Cloudflare');

		$this->report->writeln('');

		return 0;
	}

	/**
	 * How many rows carry any Cloudflare location at all. A row records an empty JSON array when
	 * the headers were absent, which is what an install behind no proxy - or with the managed
	 * transform switched off - stores.
	 */
	private function withLocation(\XF\Db\AbstractAdapter $db): int
	{
		return (int) $db->fetchOne(
			"SELECT COUNT(*) FROM xf_aqp_user_data WHERE cf_location NOT IN ('[]', '{}', '')"
		);
	}

	private function modificationSummary(\XF\Db\AbstractAdapter $db): string
	{
		$rows = $db->fetchAll(
			'SELECT enabled, COUNT(*) AS total FROM xf_template_modification WHERE addon_id = ? GROUP BY enabled',
			['Hampel/ApprovalQueuePlus']
		);

		$total = 0;
		$disabled = 0;
		foreach ($rows AS $row)
		{
			$total += (int) $row['total'];
			if (!$row['enabled'])
			{
				$disabled += (int) $row['total'];
			}
		}

		return $total === 0 ? '' : $total . ' registered' . ($disabled ? ", {$disabled} disabled" : '');
	}

	/**
	 * `dom: [-1]` is XenForo's "any day of the month", so the usual rules here describe a daily
	 * run. Rendered from the entry's own rules rather than from a sentence, because that reading
	 * was got wrong in this add-on's own documentation for three weeks.
	 */
	private function schedule(\XF\App $app): string
	{
		$entry = $app->finder('XF:CronEntry')->whereId('approvalQueuePlusCleanup')->fetchOne();

		if (!$entry)
		{
			return '';
		}

		$rules = $entry->run_rules;
		$hours = isset($rules['hours']) ? (array) $rules['hours'] : [];
		$minutes = isset($rules['minutes']) ? (array) $rules['minutes'] : [];
		$dom = isset($rules['dom']) ? (array) $rules['dom'] : [];

		$at = (count($hours) === 1 && count($minutes) === 1)
			? sprintf('%02d:%02d', $hours[0], $minutes[0])
			: 'several times a day';

		$days = ($dom === [] || in_array(-1, $dom) || in_array('-1', $dom))
			? 'every day'
			: 'day ' . implode(', ', $dom) . ' of the month';

		return "{$days} at {$at} " . $this->muted('(cron entry)')
			. ($entry->active ? '' : ' - the entry is inactive');
	}

	private function nextRun(\XF\App $app): string
	{
		$entry = $app->finder('XF:CronEntry')->whereId('approvalQueuePlusCleanup')->fetchOne();

		return $entry ? gmdate('Y-m-d H:i', $entry->next_run) . ' UTC' : '';
	}

	/**
	 * How many rows the next prune would take, counted through the repository's own finder so that
	 * this cannot report different rows from the ones the prune deletes.
	 */
	private function eligible(\XF\App $app, int $delay): string
	{
		if (!UserDataCleanUp::isEnabled())
		{
			return 'nothing - the clean-up is switched off';
		}

		if ($delay < 1)
		{
			return 'nothing - the delay is unusable, so the prune declines';
		}

		/** @var UserDataRepo $repo */
		$repo = $app->repository('Hampel\ApprovalQueuePlus:UserData');

		return $repo->findPrunableUserData()->total() . ' row(s), registered on or before '
			. gmdate('Y-m-d', $repo->getRegistrationCutoff()) . ' UTC';
	}

	private function headerCount(\XF\App $app): ?int
	{
		$container = $app->container();

		return isset($container['aqp.cloudflare'])
			? count($app->container('aqp.cloudflare')['cf.headers'])
			: null;
	}
}
