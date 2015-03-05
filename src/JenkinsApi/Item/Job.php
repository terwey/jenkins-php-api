<?php
namespace JenkinsApi\Item;

use DOMDocument;
use JenkinsApi\AbstractItem;
use JenkinsApi\Jenkins;
use RuntimeException;

/**
 *
 *
 * @package    JenkinsApi\Item
 * @author     Christopher Biel <christopher.biel@jungheinrich.de>
 * @version    $Id$
 */
class Job extends AbstractItem
{
    /**
     * @var
     */
    private $_jobName;

    /**
     * @param         $jobName
     * @param Jenkins $jenkins
     */
    public function __construct($jobName, Jenkins $jenkins)
    {
        $this->_jobName = $jobName;
        $this->_jenkins = $jenkins;

        $this->refresh();
    }

    /**
     * @return string
     */
    protected function getUrl()
    {
        return sprintf('job/%s/api/json', rawurlencode($this->_jobName));
    }

    /**
     * @return Build[]
     */
    public function getBuilds()
    {
        $builds = array();
        foreach ($this->_data->builds as $build) {
            $builds[] = $this->getBuild($build->number);
        }

        return $builds;
    }

    /**
     * @param int $buildId
     *
     * @return Build
     * @throws RuntimeException
     */
    public function getBuild($buildId)
    {
        return $this->_jenkins->getBuild($this->getName(), $buildId);
    }

    /**
     * @return array
     */
    public function getParametersDefinition()
    {
        $parameters = array();

        foreach ($this->_data->actions as $action) {
            if (!property_exists($action, 'parameterDefinitions')) {
                continue;
            }

            foreach ($action->parameterDefinitions as $parameterDefinition) {
                $default = property_exists($parameterDefinition, 'defaultParameterValue') ? $parameterDefinition->defaultParameterValue->value : null;
                $description = property_exists($parameterDefinition, 'description') ? $parameterDefinition->description : null;
                $choices = property_exists($parameterDefinition, 'choices') ? $parameterDefinition->choices : null;

                $parameters[$parameterDefinition->name] = array('default' => $default, 'choices' => $choices, 'description' => $description,);
            }
        }

        return $parameters;
    }

    /**
     * @return Build|null
     */
    public function getLastSuccessfulBuild()
    {
        if (null === $this->_data->lastSuccessfulBuild) {
            return null;
        }

        return $this->_jenkins->getBuild($this->getName(), $this->_data->lastSuccessfulBuild->number);
    }

    /**
     * @return Build|null
     */
    public function getLastBuild()
    {
        if (null === $this->_data->lastBuild) {
            return null;
        }
        return $this->_jenkins->getBuild($this->getName(), $this->_data->lastBuild->number);
    }

    /**
     * @return bool
     */
    public function isCurrentlyBuilding()
    {
        return $this->getLastBuild()->isBuilding();
    }

    /**
     * @param array $parameters
     *
     * @return bool
     */
    public function launch($parameters = array())
    {
        if (empty($parameters)) {
            $this->_jenkins->post(sprintf('job/%s/build', $this->_jobName));
        } else {
            $this->_jenkins->post(sprintf('job/%s/buildWithParameters', $this->_jobName), $parameters);
        }

        return true;
    }

    /**
     * @param array $parameters
     *
     * @param int $timeoutSeconds
     * @param int $checkIntervalSeconds
     * @return bool|Build
     */
    public function launchAndWait($parameters = array(), $timeoutSeconds = 86400, $checkIntervalSeconds = 5)
    {
        if (!$this->isCurrentlyBuilding()) {
            $lastNumber = $this->getLastBuild()->getNumber();
            $startTime = time();
            $this->launch($parameters);

            $build = $this->getLastBuild();

            while ((time() < $startTime + $timeoutSeconds) && ($build->getNumber() == $lastNumber + 1 && !$build->isBuilding())) {
                sleep($checkIntervalSeconds);
                $build->refresh();
            }

            return $build;

        }
        return false;
    }

    public function delete()
    {
        if (!$this->getJenkins()->post(sprintf('job/%s/doDelete', $this->_jobName))) {
            throw new RuntimeException(sprintf('Error deleting job %s on %s', $this->_jobName, $this->getJenkins()->getBaseUrl()));
        }
    }

    public function getConfig()
    {
        $config = $this->getJenkins()->get(sprintf('job/%s/config.xml', $this->_jobName));
        if ($config) {
            throw new RuntimeException(sprintf('Error during getting configuation for job %s', $this->_jobName));
        }
        return $config;
    }

    /**
     * @param string $jobname
     * @param DomDocument $document
     *
     * @deprecated use setJobConfig instead
     */
    public function setConfigFromDomDocument($jobname, DomDocument $document)
    {
        $this->setJobConfig($jobname, $document->saveXML());
    }

    /**
     * @param string|array $configuration
     *
     */
    public function setJobConfig($configuration)
    {
        $return = $this->getJenkins()->post(sprintf('job/%s/config.xml', $this->_jobName), $configuration, array(CURLOPT_HTTPHEADER => array('Content-Type: text/xml')));
        if ($return) {
            throw new RuntimeException(sprintf('Error during setting configuration for job %s', $this->_jobName));
        }
    }

    /**
     * @return boolean
     */
    public function isBuildable()
    {
        return $this->_data->buildable;
    }

    /**
     * @return string
     */
    public function getColor()
    {
        return $this->get('color');
    }

    /**
     * @return string
     */
    public function getName()
    {
        return $this->get('name');
    }

    public function __toString()
    {
        return $this->_jobName;
    }
}
