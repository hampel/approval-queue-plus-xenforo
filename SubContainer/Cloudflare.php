<?php namespace Hampel\ApprovalQueuePlus\SubContainer;

use Hampel\ApprovalQueuePlus\Data\CountryCodes;
use XF\SubContainer\AbstractSubContainer;

class Cloudflare extends AbstractSubContainer
{
    public function initialize()
    {
        $container = $this->container;

        $container['cf.headers'] = function($c)
        {
            return [
                'HTTP_CF_IPCITY' => 'city',
                'HTTP_CF_IPCOUNTRY' => 'country_code',
                'HTTP_CF_IPCONTINTENT' => 'continent_code',
                'HTTP_CF_IPLONGITUDE' => 'longitude',
                'HTTP_CF_IPLATITUDE' => 'latitude',
                'HTTP_CF_REGION' => 'region',
                'HTTP_CF_REGION_CODE' => 'region_code',
                'HTTP_CF_METRO_CODE' => 'metro_code',
                'HTTP_CF_POSTAL_CODE' => 'postal_code',
                'HTTP_CF_TIMEZONE' => 'timezone'
            ];
        };
    }

    protected function getHeaders()
    {
        return $this->container['cf.headers'];
    }

    public function getCloudflareLocation()
    {
        $request = \XF::app()->request();

        /** @var CountryCodes $data */
        $data = \XF::app()->data('Hampel\ApprovalQueuePlus::CountryCodes');

        $location = [];

        foreach ($this->getHeaders() as $header => $key)
        {
            $value = $request->getServer($header);

            if ($value)
            {
                if ($header == 'HTTP_CF_IPCOUNTRY')
                {
                    $location['country'] = $data->getCountry($value, $value);
                }
                elseif ($header == 'HTTP_CF_IPCONTINTENT')
                {
                    $location['continent'] = $data->getContinent($value, $value);
                }

                $location[$key] = $value;
            }
        }

        return $location;
    }
}
