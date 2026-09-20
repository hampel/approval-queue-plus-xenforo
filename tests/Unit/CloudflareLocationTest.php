<?php namespace Tests\Unit;

use Hampel\ApprovalQueuePlus\SubContainer\Cloudflare;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use XF\Http\Request;

/**
 * The add-on's only source of location data is a fixed map of Cloudflare request headers, and a
 * misspelling in it is invisible: the template renders each row only when its key is present, so
 * a header that never matches produces a page that looks correct with a row quietly missing.
 *
 * `HTTP_CF_IPCONTINTENT` sat in that map from 3.5.0 to 3.5.3 and cost the continent rows.
 */
class CloudflareLocationTest extends TestCase
{
	/**
	 * Every header Cloudflare's "Add visitor location headers" managed transform sends, spelled
	 * as Cloudflare spells it, with the key `getCloudflareLocation()` must file it under.
	 *
	 * Providers are static and run before the app boots, so this holds no XF state.
	 */
	public static function documentedHeaders(): array
	{
		return [
			'city'          => ['CF-IPCity',      'Sydney',            'city'],
			'country code'  => ['CF-IPCountry',   'AU',                'country_code'],
			'continent code'=> ['CF-IPContinent', 'OC',                'continent_code'],
			'longitude'     => ['CF-IPLongitude', '151.20732',         'longitude'],
			'latitude'      => ['CF-IPLatitude',  '-33.86785',         'latitude'],
			'region'        => ['CF-Region',      'New South Wales',   'region'],
			'region code'   => ['CF-Region-Code', 'NSW',               'region_code'],
			'metro code'    => ['CF-Metro-Code',  '501',               'metro_code'],
			'postal code'   => ['CF-Postal-Code', '2000',              'postal_code'],
			'timezone'      => ['CF-Timezone',    'Australia/Sydney',  'timezone'],
		];
	}

	#[DataProvider('documentedHeaders')]
	public function test_each_documented_header_is_mapped($header, $value, $expectedKey)
	{
		$location = $this->locationFor([$header => $value]);

		$this->assertArrayHasKey($expectedKey, $location,
			"Cloudflare sends '{$header}'; nothing in the header map matched it");
		$this->assertSame($value, $location[$expectedKey]);
	}

	public function test_country_and_continent_names_are_derived_from_their_codes()
	{
		$location = $this->locationFor([
			'CF-IPCountry'   => 'AU',
			'CF-IPContinent' => 'OC',
		]);

		$this->assertSame('Australia', $location['country']);
		$this->assertSame('Oceania', $location['continent']);
	}

	public function test_an_unknown_code_falls_back_to_the_code_itself()
	{
		$location = $this->locationFor(['CF-IPCountry' => 'ZZ']);

		$this->assertSame('ZZ', $location['country']);
	}

	public function test_a_request_with_no_cloudflare_headers_yields_nothing()
	{
		$this->assertSame([], $this->locationFor([]));
	}

	public function test_headers_present_but_empty_are_skipped()
	{
		$location = $this->locationFor([
			'CF-IPCity'     => 'Sydney',
			'CF-Metro-Code' => '',
		]);

		$this->assertArrayHasKey('city', $location);
		$this->assertArrayNotHasKey('metro_code', $location);
	}

	/**
	 * Build a real request from the given Cloudflare headers and run the sub-container over it.
	 *
	 * A real `XF\Http\Request` rather than a mock, so `getServer()` does the same name mangling
	 * PHP does — which is the half of the bug a mocked request would have hidden.
	 */
	protected function locationFor(array $headers): array
	{
		$server = [];
		foreach ($headers as $name => $value)
		{
			$server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
		}

		$this->swap('request', new Request($this->app()->inputFilterer(), [], [], [], $server));

		/** @var Cloudflare $cf */
		$cf = $this->app()->container('aqp.cloudflare');

		return $cf->getCloudflareLocation();
	}
}
