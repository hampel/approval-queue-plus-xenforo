<?php namespace Hampel\ApprovalQueuePlus\Factory;

use Hampel\Seeder\Factory\AbstractFactory;
use Hampel\Seeder\Factory\UserFactory;

/**
 * A recorded registration: the row the Approval Queue displays, belonging by default to a member
 * awaiting approval, which is the only state a moderator ever sees one in.
 *
 * Development tooling, like the test suite - `build.json` keeps this directory out of the release.
 *
 * **The location is not written here. It is produced by the add-on's own map**, by handing
 * `SubContainer\Cloudflare::getCloudflareLocation()` a request carrying the headers Cloudflare
 * would have sent from that place - the same swap `Cli\Command\Validate` makes, and for the same
 * reason: the method reads `\XF::app()->request()` rather than taking one.
 *
 * That is worth the ten lines because the alternative is a hand-written array of keys. The suite
 * cannot catch it drifting - an add-on's tests load that add-on alone, so Seeder's classes are
 * absent and this factory cannot even be constructed under PHPUnit - so a seeded row that
 * disagreed with the map would be wrong in exactly the way nothing here would report. Built this
 * way, a seeded row is what a registration from that city really records, including the keys the
 * map skips: Cloudflare sends no metro code outside the United States, and an empty header is
 * dropped rather than stored.
 */
class UserDataFactory extends AbstractFactory
{
	protected $entity = 'Hampel\ApprovalQueuePlus:UserData';

	/**
	 * Real places, because a queue of plausible ones is what a moderator has to read. The keys are
	 * Cloudflare's own header names, so this is what the forum would have received.
	 */
	protected static $places = [
		[
			'HTTP_CF_IPCITY' => 'Sydney',
			'HTTP_CF_IPCOUNTRY' => 'AU',
			'HTTP_CF_IPCONTINENT' => 'OC',
			'HTTP_CF_IPLONGITUDE' => '151.20732',
			'HTTP_CF_IPLATITUDE' => '-33.86785',
			'HTTP_CF_REGION' => 'New South Wales',
			'HTTP_CF_REGION_CODE' => 'NSW',
			'HTTP_CF_POSTAL_CODE' => '2000',
			'HTTP_CF_TIMEZONE' => 'Australia/Sydney',
		],
		[
			'HTTP_CF_IPCITY' => 'Manchester',
			'HTTP_CF_IPCOUNTRY' => 'GB',
			'HTTP_CF_IPCONTINENT' => 'EU',
			'HTTP_CF_IPLONGITUDE' => '-2.23743',
			'HTTP_CF_IPLATITUDE' => '53.48095',
			'HTTP_CF_REGION' => 'England',
			'HTTP_CF_REGION_CODE' => 'ENG',
			'HTTP_CF_POSTAL_CODE' => 'M1',
			'HTTP_CF_TIMEZONE' => 'Europe/London',
		],
		[
			// the one place that sends a metro code, which Cloudflare sends only for the US
			'HTTP_CF_IPCITY' => 'Austin',
			'HTTP_CF_IPCOUNTRY' => 'US',
			'HTTP_CF_IPCONTINENT' => 'NA',
			'HTTP_CF_IPLONGITUDE' => '-97.74306',
			'HTTP_CF_IPLATITUDE' => '30.26715',
			'HTTP_CF_REGION' => 'Texas',
			'HTTP_CF_REGION_CODE' => 'TX',
			'HTTP_CF_METRO_CODE' => '635',
			'HTTP_CF_POSTAL_CODE' => '78701',
			'HTTP_CF_TIMEZONE' => 'America/Chicago',
		],
		[
			'HTTP_CF_IPCITY' => 'Osaka',
			'HTTP_CF_IPCOUNTRY' => 'JP',
			'HTTP_CF_IPCONTINENT' => 'AS',
			'HTTP_CF_IPLONGITUDE' => '135.50218',
			'HTTP_CF_IPLATITUDE' => '34.69374',
			'HTTP_CF_REGION' => 'Osaka',
			'HTTP_CF_REGION_CODE' => '27',
			'HTTP_CF_POSTAL_CODE' => '530-0001',
			'HTTP_CF_TIMEZONE' => 'Asia/Tokyo',
		],
		[
			'HTTP_CF_IPCITY' => 'Porto Alegre',
			'HTTP_CF_IPCOUNTRY' => 'BR',
			'HTTP_CF_IPCONTINENT' => 'SA',
			'HTTP_CF_IPLONGITUDE' => '-51.23019',
			'HTTP_CF_IPLATITUDE' => '-30.03283',
			'HTTP_CF_REGION' => 'Rio Grande do Sul',
			'HTTP_CF_REGION_CODE' => 'RS',
			'HTTP_CF_POSTAL_CODE' => '90010',
			'HTTP_CF_TIMEZONE' => 'America/Sao_Paulo',
		],
	];

	public function definition()
	{
		$headers = self::$places[array_rand(self::$places)];

		return [
			'user_id' => UserFactory::new()->moderated(),
			'user_agent' => $this->context()->faker->userAgent(),
			'iso_code' => $headers['HTTP_CF_IPCOUNTRY'],
			'cf_location' => $this->locationFrom($headers),
		];
	}

	/**
	 * A client that sent no `User-Agent` header. The column is empty rather than absent, and the
	 * macro omits the row - the shape that refused every such registration before 3.6.2.
	 */
	public function withoutUserAgent()
	{
		return $this->state(['user_agent' => '']);
	}

	/**
	 * A registration from a forum that is not behind Cloudflare, or is behind it with
	 * *Add visitor location headers* switched off. Nothing is recorded but the user agent.
	 */
	public function withoutLocation()
	{
		return $this->state(['iso_code' => '', 'cf_location' => []]);
	}

	/**
	 * A row recorded before 3.6.0, when the continent header name was misspelled in the map.
	 * Nothing backfills these, so every forum running this add-on still holds some.
	 */
	public function withoutContinent()
	{
		return $this->state(function (array $attributes) {
			$location = $attributes['cf_location'];
			unset($location['continent'], $location['continent_code']);

			return ['cf_location' => $location];
		});
	}

	/**
	 * A row the clean-up is entitled to delete: its member has since been approved, so the queue
	 * no longer shows it, and it registered far enough back to be past any plausible delay.
	 */
	public function forApprovedUser($daysAgo = 90)
	{
		return $this->state([
			'user_id' => UserFactory::new()->state([
				'user_state' => 'valid',
				'register_date' => \XF::$time - ($daysAgo * 86400),
			]),
		]);
	}

	/**
	 * Hand the headers to the real map, by swapping the application's request for one carrying
	 * them and putting the original back - restored even if the map throws.
	 */
	protected function locationFrom(array $headers): array
	{
		$app = \XF::app();
		$container = $app->container();
		$original = $container['request'];

		$container['request'] = new \XF\Http\Request(
			$app->inputFilterer(),
			[],
			[],
			[],
			$headers + ['REQUEST_METHOD' => 'GET']
		);

		try
		{
			return $container['aqp.cloudflare']->getCloudflareLocation();
		}
		finally
		{
			$container['request'] = $original;
		}
	}
}
