<?php
/**
 * JenkinsNew.php
 *
 * @package    Atlas
 * @author     Christopher Biel <christopher.biel@jungheinrich.de>
 * @copyright  2013 Jungheinrich AG
 * @license    Proprietary license
 * @version    $Id$
 */

namespace JenkinsApi;

use InvalidArgumentException;
use JenkinsApi\Item\Build;
use JenkinsApi\Item\Job;
use JenkinsApi\Item\Node;
use JenkinsApi\Item\Queue;
use JenkinsApi\Item\View;
use RuntimeException;
use stdClass;

/**
 * Wrapper for general
 *
 * @package    JenkinsApi
 * @author     Christopher Biel <christopher.biel@jungheinrich.de>
 * @version    $Id$
 */
class Jenkins
{
    /**
     * @var bool
     */
    private $_verbose = false;

    /**
     * @var string
     */
    private $_baseUrl;

    /**
     * @var string
     */
    private $_urlExtension = '/api/json';

    /**
     * Whether or not to retrieve and send anti-CSRF crumb tokens
     * with each request
     *
     * Defaults to false for backwards compatibility
     *
     * @var boolean
     */
    private $_crumbsEnabled = false;

    /**
     * The anti-CSRF crumb to use for each request
     *
     * Set when crumbs are enabled, by requesting a new crumb from Jenkins
     *
     * @var string
     */
    private $_crumb;

    /**
     * The header to use for sending anti-CSRF crumbs
     *
     * Set when crumbs are enabled, by requesting a new crumb from Jenkins
     *
     * @var string
     */
    private $_crumbRequestField;

    /**
     * @param string $baseUrl
     */
    public function __construct($baseUrl)
    {
        $this->_baseUrl = $baseUrl . ((substr($baseUrl, -1) === '/') ? '' : '/');
    }

    /**
     * @param string|Job $jobName
     * @param int|string $buildNumber
     *
     * @return Build
     */
    public function getBuild($jobName, $buildNumber)
    {
        return new Build($buildNumber, $jobName, $this);
    }

    /**
     * @param string $jobName
     *
     * @return Job
     */
    public function getJob($jobName)
    {
        return new Job($jobName, $this);
    }

    /**
     * @return Job[]
     */
    public function getJobs()
    {
        $data = $this->get('api/json');

        $jobs = array();
        foreach ($data->jobs as $job) {
            $jobs[$job->name] = $this->getJob($job->name);
        }

        return $jobs;
    }

    /**
     * @return Queue
     * @throws RuntimeException
     */
    public function getQueue()
    {
        $data = $this->get('queue/api/json');

        return new Queue($data, $this);
    }

    /**
     * @param string $url
     * @param int    $depth
     * @param array  $params
     * @param array  $curlOpts
     *
     * @throws RuntimeException
     * @return stdClass
     */
    public function get($url, $depth = 1, $params = array(), array $curlOpts = [])
    {
//        $url = str_replace(' ', '%20', sprintf('%s' . $url . '?depth=' . $depth, $this->_baseUrl));
        $url = sprintf('%s', $this->_baseUrl) . $url . '?depth=' . $depth;
        if ($params) {
            foreach ($params as $key => $val) {
                $url .= '&' . $key . '=' . $val;
            }
        }
        $curl = curl_init($url);
        if ($curlOpts) {
            curl_setopt_array($curl, $curlOpts);
        }
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        $ret = curl_exec($curl);

        $response_info = curl_getinfo($curl);

        if (200 != $response_info['http_code']) {
            throw new RuntimeException(sprintf('Error during getting information from url %s', $url));
        }

        if (curl_errno($curl)) {
            throw new RuntimeException(sprintf('Error during getting information from url %s', $url));
        }
        $data = json_decode($ret);
        if (!$data instanceof stdClass) {
            throw new RuntimeException('Error during json_decode');
        }

        return $data;
    }

    /**
     * @param string $url
     * @param array  $parameters
     * @param array  $curlOpts
     *
     * @throws RuntimeException
     * @return bool
     */
    public function post($url, array $parameters = [], array $curlOpts = [])
    {
        $url = sprintf('%s', $this->_baseUrl) . $url;

        $curl = curl_init($url);
        if ($curlOpts) {
            curl_setopt_array($curl, $curlOpts);
        }
        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($parameters));

        $headers = (isset($curlOpts[CURLOPT_HTTPHEADER])) ? $curlOpts[CURLOPT_HTTPHEADER] : array();

        if ($this->areCrumbsEnabled()) {
            $headers[] = $this->getCrumbHeader();
        }

        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);

        $return = curl_exec($curl);
        return (curl_errno($curl)) ?: $return;
    }

    /**
     * @return boolean
     */
    public function isAvailable()
    {
        $curl = curl_init($this->_baseUrl . '/api/json');
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_exec($curl);

        if (curl_errno($curl)) {
            return false;
        } else {
            try {
                $this->getQueue();
            } catch (RuntimeException $e) {
                return false;
            }
        }

        return true;
    }

    public function getCrumbHeader()
    {
        return "$this->_crumbRequestField: $this->_crumb";
    }

    /**
     * Get the status of anti-CSRF crumbs
     *
     * @return boolean Whether or not crumbs have been enabled
     */
    public function areCrumbsEnabled()
    {
        return $this->_crumbsEnabled;
    }

    public function requestCrumb()
    {
        $url = sprintf('%s/crumbIssuer/api/json', $this->_baseUrl);

        $curl = curl_init($url);

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);

        $ret = curl_exec($curl);

        if (curl_errno($curl)) {
            throw new RuntimeException('Error getting csrf crumb');
        }

        $crumbResult = json_decode($ret);

        if (!$crumbResult instanceof stdClass) {
            throw new RuntimeException('Error during json_decode of csrf crumb');
        }

        return $crumbResult;
    }

    /**
     * Enable the use of anti-CSRF crumbs on requests
     *
     * @return void
     */
    public function enableCrumbs()
    {
        $this->_crumbsEnabled = true;

        $crumbResult = $this->requestCrumb();

        if (!$crumbResult || !is_object($crumbResult)) {
            $this->_crumbsEnabled = false;

            return;
        }

        $this->_crumb = $crumbResult->crumb;
        $this->_crumbRequestField = $crumbResult->crumbRequestField;
    }

    /**
     * Get the currently building jobs
     *
     * @return Job[]
     */
    public function getCurrentlyBuildingJobs()
    {
        $url = sprintf("%s", $this->_baseUrl)
            . "/api/xml?tree=jobs[name,url,color]&xpath=/hudson/job[ends-with(color/text(),\%22_anime\%22)]&wrapper=jobs";
        $curl = curl_init($url);

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        $ret = curl_exec($curl);

        $errorMessage = sprintf('Error during getting all currently building jobs on %s', $this->_baseUrl);

        if (curl_errno($curl)) {
            throw new RuntimeException($errorMessage);
        }
        $xml = simplexml_load_string($ret);
        $builds = $xml->xpath('/jobs');
        $buildingJobs = [];
        foreach ($builds as $build) {
            $buildingJobs[] = new Job($build->job->name, $this);
        }

        return $buildingJobs;
    }

    /**
     * @return View[]
     */
    public function getViews()
    {
        $data = $this->get('api/json');
        $views = array();
        foreach ($data->views as $view) {
            $views[] = $this->getView($view->name);
        }
        return $views;
    }

    /**
     * @return View|null
     */
    public function getPrimaryView()
    {
        $data = $this->get('api/json');

        $primaryView = null;
        if (property_exists($data, 'primaryView')) {
            $primaryView = $this->getView($data->primaryView->name);
        }

        return $primaryView;
    }

    /**
     * @param string $jobname
     * @param string $xmlConfiguration
     *
     * @throws InvalidArgumentException
     * @return void
     */
    public function createJob($jobname, $xmlConfiguration)
    {
        $url = sprintf('%s/createItem?name=%s', $this->getBaseUrl(), $jobname);
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_POST, 1);

        curl_setopt($curl, CURLOPT_POSTFIELDS, $xmlConfiguration);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);

        $headers = array('Content-Type: text/xml');

        if ($this->areCrumbsEnabled()) {
            $headers[] = $this->getCrumbHeader();
        }

        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($curl);

        if (curl_getinfo($curl, CURLINFO_HTTP_CODE) != 200) {
            throw new InvalidArgumentException(sprintf('Job %s already exists', $jobname));
        }
        if (curl_errno($curl)) {
            throw new RuntimeException(sprintf('Error creating job %s', $jobname));
        }
    }

    /**
     * @return Node[]
     */
    public function getNodes()
    {
        $data = json_decode($this->get('computer/api/json'));
        $nodes = array();
        foreach ($data->computer as $node) {
            $nodes[] = new Node($node->displayName, $this);
        }
        return $nodes;
    }

    /**
     * Go into prepare shutdown mode.
     * This prevents new jobs beeing started
     */
    public function prepareShutdown()
    {
        $this->post('quietDown');
    }

    /**
     * Exit prepare shutdown mode
     * This allows jobs beeing started after shutdown prepare (but before actual restart)
     */
    public function cancelPrepareShutdown()
    {
        $this->post('cancelQuietDown');
    }

    /**
     * @param string $viewName
     *
     * @return View
     * @throws RuntimeException
     */
    public function getView($viewName)
    {
        return new View($viewName, $this);
    }

    /**
     * Disable the use of anti-CSRF crumbs on requests
     *
     * @return void
     */
    public function disableCrumbs()
    {
        $this->_crumbsEnabled = false;
    }

    /**
     * @return string
     */
    public function getUrlExtension()
    {
        return $this->_urlExtension;
    }

    /**
     * @param string $urlExtension
     */
    public function setUrlExtension($urlExtension)
    {
        $this->_urlExtension = $urlExtension;
    }

    /**
     * @return string
     */
    public function getBaseUrl()
    {
        return $this->_baseUrl;
    }

    /**
     * @param string $baseUrl
     */
    public function setBaseUrl($baseUrl)
    {
        $this->_baseUrl = $baseUrl;
    }

    /**
     * @return boolean
     */
    public function isVerbose()
    {
        return $this->_verbose;
    }

    /**
     * @param boolean $verbose
     */
    public function setVerbose($verbose)
    {
        $this->_verbose = $verbose;
    }
}
