<?php

namespace SilverStripe\Intercom;

use LogicException;
use Intercom\IntercomClient;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Environment;
use SilverStripe\Security\Member;
use SilverStripe\ORM\SS_List;

/**
 * Entry point for interaction with with Intercom.
 */
class Intercom
{
    use Configurable;

    private $personalAccessToken;
    private $appId;
    private $client;

    public function __construct()
    {
        if ($accessToken = self::getSetting('INTERCOM_PERSONAL_ACCESS_TOKEN')) {
            $this->personalAccessToken = $accessToken;
        }
        if ($appId = self::getSetting('INTERCOM_APP_ID')) {
            $this->appId = $appId;
        }
    }

    public function getPersonalAccessToken()
    {
        if (!$this->personalAccessToken) {
            throw new LogicException("Intercom Personal Access Token not set! Define INTERCOM_PERSONAL_ACCESS_TOKEN or use Injector to set Personal Access Token");
        }
        return $this->personalAccessToken;
    }

    public function setPersonalAccessToken($token)
    {
        $this->personalAccessToken = $token;
    }

    public function getAppId()
    {
        if (!$this->appId) {
            throw new LogicException("Intercom App ID not set! Define INTERCOM_APP_ID or use Injector to set AppId");
        }
        return $this->appId;
    }
    public function setAppId($appId)
    {
        $this->appId = $appId;
    }

    public function getClient()
    {
        if (!$this->client) {
            $this->client = Injector::inst()->create(IntercomClient::class, $this->getPersonalAccessToken(), null);
        }
        return $this->client;
    }


    /**
     * Return a list of all users for this application.
     * Define via the 'user_list' project, which should be '%$' followed by an Injector service anem.
     * Defaults to all Members
     * @return DataList
     */
    public function getUserList()
    {
        if ($userList = static::config()->get('user_list')) {
            if (substr($userList, 0, 2) != '%$') {
                throw new \InvalidArgumentException("Please set user_list to a string of the form %\$ServiceName");
            }
            return Injector::inst()->get(substr($userList, 2));
        }

        // Default all users
        return Member::get();
    }

    /**
     * Bulk load a set of members using the same meta-data rules as if they were to log in
     * @param SS_List $members A list of members
     * @return IntercomBulkJob
     */
    public function bulkLoadUsers(SS_List $members)
    {
        $userFields = static::config()->get('user_fields');
        $companyFields = static::config()->get('company_fields');

        $scriptTags = new IntercomScriptTags();

        // Build the batch API submission
        foreach ($members as $member) {
            $settings = $scriptTags->getIntercomSettings($member);

            unset($settings['app_id']);
            unset($settings['user_hash']);

            foreach ($settings as $k => $v) {
                if (!in_array($k, $userFields)) {
                    $settings['custom_attributes'][$k] = $v;
                    unset($settings[$k]);
                }
            }

            if (isset($settings['company'])) {
                foreach ($settings['company'] as $k => $v) {
                    if (!in_array($k, $companyFields)) {
                        $settings['company']['custom_attributes'][$k] = $v;
                        unset($settings['company'][$k]);
                    }
                }
            }

            $items[] = [
                'data_type' => 'user',
                'method' => 'post',
                'data' => $settings,
            ];
        }

        $result = $this->getClient()->bulk->users(['items' => $items]);

        return $this->getBulkJob($result->id);
    }


    /**
     * Return an IntercomBulkJob object for the given job
     * @param string $id The job ID
     * @return IntercomBulkJob
     */
    public function getBulkJob($id)
    {
        return Injector::inst()->create(IntercomBulkJob::class, $this->getClient(), $id);
    }
    /**
     * Track an event with the current user.
     *
     * @param  string $eventName Event name. Passed straight to intercom.
     * @param  array $eventData A map of event data. Passed straight to intercom.
     * @param Member $member - if not provided, it will try to use Member::currentUser();
     * @throws LogicException If no user is logged in
     */
    public function trackEvent($eventName, $eventData = [], Member $member = null)
    {
        $payload = array(
            'event_name' => $eventName,
            'created_at' => time(),
        );

        $scriptTags = Injector::inst()->create(IntercomScriptTags::class);
        $settings = $scriptTags->getIntercomSettings($member);

        if (empty($settings['email']) && empty($settings['user_id'])) {
            throw new LogicException("Can't track event when no user logged in");
        }

        if (!empty($settings['email'])) {
            $payload['email'] = $settings['email'];
        }
        if (!empty($settings['user_id'])) {
            $payload['user_id'] = $settings['user_id'];
        }

        if ($eventData) {
            $payload['metadata'] = $eventData;
        }

        $this->getClient()->events->create($payload);
    }

    /**
     * Get a setting from either the environment or a constant if defined
     *
     * @param string $varName
     * @return mixed|false The value of the property from getenv or a constant, or false if not found
     */
    public static function getSetting($varName)
    {
        if (Environment::getEnv($varName)) {
            return Environment::getEnv($varName);
        }

        if (defined($varName)) {
            return constant($varName);
        }

        return false;
    }
}
