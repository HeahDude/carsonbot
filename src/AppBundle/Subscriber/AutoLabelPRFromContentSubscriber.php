<?php

namespace AppBundle\Subscriber;

use AppBundle\Event\GitHubEvent;
use AppBundle\GitHubEvents;
use AppBundle\Issues\GitHub\CachedLabelsApi;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Looks at new pull requests and auto-labels based on text.
 */
class AutoLabelPRFromContentSubscriber implements EventSubscriberInterface
{
    private $labelsApi;

    public function __construct(CachedLabelsApi $labelsApi)
    {
        $this->labelsApi = $labelsApi;
    }

    /**
     * @param GitHubEvent $event
     */
    public function onPullRequest(GitHubEvent $event)
    {
        $data = $event->getData();
        if (!in_array($action = $data['action'], array('opened', 'edited'), true)) {
            $event->setResponseData(array('unsupported_action' => $action));

            return;
        }

        $prNumber = $data['pull_request']['number'];
        $prTitle = $data['pull_request']['title'];
        $prBody = $data['pull_request']['body'];
        $prLabels = array();

        // the PR title usually contains one or more labels
        foreach ($this->extractLabels($prTitle) as $label) {
            $prLabels[] = $label;
        }

        // the PR body usually indicates if this is a Bug, Feature, BC Break or Deprecation
        if (preg_match('/\|\s*Bug fix\?\s*\|\s*yes\s*/', $prBody, $matches)) {
            $prLabels[] = 'Bug';
        }
        if (preg_match('/\|\s*New feature\?\s*\|\s*yes\s*/', $prBody, $matches)) {
            $prLabels[] = 'Feature';
        }
        if (preg_match('/\|\s*BC breaks\?\s*\|\s*yes\s*/', $prBody, $matches)) {
            $prLabels[] = 'BC Break';
        }
        if (preg_match('/\|\s*Deprecations\?\s*\|\s*yes\s*/', $prBody, $matches)) {
            $prLabels[] = 'Deprecation';
        }

        $previousLabels = $this->labelsApi->getIssueLabels($prNumber, $event->getRepository());
        $addLabels = array_diff($prLabels, $previousLabels);

        if (count($addLabels)) {
            $this->labelsApi->addIssueLabels($prNumber, $prLabels, $event->getRepository());
        }

        $removeLabels = array_diff($previousLabels, $prLabels);
        
        if (count($removeLabels)) {
            foreach ($removeLabels as $label) {
                if (false !== strpos($label, 'Status: ')) {
                    $this->labelsApi->removeIssueLabel($prNumber, $label, $event->getRepository());
                }
            }
        }

        $event->setResponseData(array(
            'pull_request' => $prNumber,
            'pr_labels' => $addLabels,
        ));
    }

    private function extractLabels($prTitle)
    {
        $labels = array();

        // e.g. "[PropertyAccess] [RFC] [WIP] Allow custom methods on property accesses"
        if (preg_match_all('/\[(?P<labels>.+)\]/U', $prTitle, $matches)) {
            foreach ($matches['labels'] as $label) {
                if (in_array($label, $this->getValidLabels())) {
                    $labels[] = $label;
                }
            }
        }

        return $labels;
    }

    /**
     * TODO: get valid labels from the repository via GitHub API.
     */
    private function getValidLabels()
    {
        return array(
            'Asset', 'BC Break', 'BrowserKit', 'Bug', 'Cache', 'ClassLoader',
            'Config', 'Console', 'Critical', 'CssSelector', 'Debug', 'DebugBundle',
            'DependencyInjection', 'Deprecation', 'Doctrine', 'DoctrineBridge',
            'DomCrawler', 'Drupal related', 'DX', 'Easy Pick', 'Enhancement',
            'EventDispatcher', 'ExpressionLanguage', 'Feature', 'Filesystem',
            'Finder', 'Form', 'FrameworkBundle', 'HttpFoundation', 'HttpKernel',
            'Intl', 'Ldap', 'Locale', 'MonologBridge', 'OptionsResolver',
            'PhpUnitBridge', 'Process', 'PropertyAccess', 'PropertyInfo', 'Ready',
            'RFC', 'Routing', 'Security', 'SecurityBundle', 'Serializer',
            'Stopwatch', 'Templating', 'Translator', 'TwigBridge', 'TwigBundle',
            'Unconfirmed', 'Validator', 'VarDumper', 'WebProfilerBundle', 'Yaml',
        );
    }

    public static function getSubscribedEvents()
    {
        return array(
            GitHubEvents::PULL_REQUEST => 'onPullRequest',
        );
    }
}
