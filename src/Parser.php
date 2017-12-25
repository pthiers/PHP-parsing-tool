<?php

namespace ParserGenerator;

require_once 'SyntaxTreeNode/Base.php';
require_once 'SyntaxTreeNode/Leaf.php';
require_once 'SyntaxTreeNode/PredefinedString.php';
require_once 'SyntaxTreeNode/Numeric.php';
require_once 'SyntaxTreeNode/Branch.php';
require_once 'SyntaxTreeNode/Root.php';
require_once 'SyntaxTreeNode/Series.php';
require_once 'GrammarNode/NodeInterface.php';
require_once 'GrammarNode/BranchInterface.php';
require_once 'GrammarNode/BaseNode.php';
require_once 'GrammarNode/LeafInterface.php';
require_once 'GrammarNode/Branch.php';
require_once 'GrammarNode/NaiveBranch.php';
require_once 'GrammarNode/PEGBranch.php';
require_once 'GrammarNode/BranchFactory.php';
require_once 'GrammarNode/Decorator.php';
require_once 'GrammarNode/BranchDecorator.php';
require_once 'GrammarNode/BranchExtraCondition.php';
require_once 'GrammarNode/BranchStringCondition.php';
require_once 'GrammarNode/Text.php';
require_once 'GrammarNode/TextS.php';
require_once 'GrammarNode/PredefinedString.php';
require_once 'GrammarNode/PredefinedSimpleString.php';
require_once 'GrammarNode/Regex.php';
require_once 'GrammarNode/WhitespaceContextCheck.php';
require_once 'GrammarNode/WhitespaceNegativeContextCheck.php';
require_once 'GrammarNode/Numeric.php';
require_once 'GrammarNode/Series.php';
require_once 'GrammarNode/Choice.php';
require_once 'GrammarNode/Lookahead.php';
require_once 'GrammarNode/AnyText.php';
require_once 'GrammarNode/ItemRestrictions.php';
require_once 'GrammarNode/ErrorTrackDecorator.php';
require_once 'RegexUtil.php';
require_once 'GrammarParser.php';

namespace ParserGenerator;

class Parser
{
    public $cache;
    public $grammar = array();

    public $maxIndex;
    public $expected;
    public $options;

    protected function buildFromArray($grammar, $options)
    {
        $this->grammar = $grammar;
        $this->options = $options;

        foreach ($this->grammar as $name => $node) {
            $grammarNode = new \ParserGenerator\GrammarNode\Branch($name);
            $grammarNode->setParser($this);
            $grammarNode = new \ParserGenerator\GrammarNode\ErrorTrackDecorator($grammarNode);
            $this->grammar[$name] = $grammarNode;
        }

        $this->grammar['string'] = new \ParserGenerator\GrammarNode\PredefinedString(true);

        foreach ($grammar as $name => $node) {
            $grammarNodeOptions = array();
            foreach ($node as $optionIndex => $option) {
                foreach ((array)$option as $seqIndex => $seq) {
                    if (is_string($seq)) {
                        if (substr($seq, 0, 1) == ':') {
                            if (substr($seq, 1, 1) == '/') {
                                $grammarNodeOptions[$optionIndex][$seqIndex] = new \ParserGenerator\GrammarNode\ErrorTrackDecorator(new \ParserGenerator\GrammarNode\Regex(substr($seq,
                                    1), true));
                            } else {
                                $grammarNodeOptions[$optionIndex][$seqIndex] = $this->grammar[substr($seq, 1)];
                            }
                        } else {
                            $grammarNodeOptions[$optionIndex][$seqIndex] = new \ParserGenerator\GrammarNode\ErrorTrackDecorator(
                                new \ParserGenerator\GrammarNode\TextS($seq));
                        }
                    } elseif ($seq instanceof \ParserGenerator\GrammarNode\NodeInterface) {
                        $grammarNodeOptions[$optionIndex][$seqIndex] = new \ParserGenerator\GrammarNode\ErrorTrackDecorator($seq);
                    } else {
                        throw new \Exception('incorrect sequenceitem');
                    }
                }
            }
            $this->grammar[$name]->getDecoratedNode()->setNode($grammarNodeOptions);
        }
    }

    public function iterateOverNodes($callback)
    {
        $visitedNodes = array();
        foreach ($this->grammar as $node) {
            $this->_iterateOverNodes($node, $callback, $visitedNodes);
        }
    }

    protected function _iterateOverNodes($node, $callback, &$visitedNodes)
    {
        $hash = spl_object_hash($node);

        if (empty($visitedNodes[$hash])) {
            $visitedNodes[$hash] = true;
            $callback($node);

            if ($node instanceof GrammarNode\ErrorTrackDecorator) {
                return $this->_iterateOverNodes($node->getDecoratedNode(), $callback, $visitedNodes);
            } elseif (method_exists($node, 'getNode')) {
                foreach ($node->getNode() as $sequence) {
                    foreach ($sequence as $subnode) {
                        $this->_iterateOverNodes($subnode, $callback, $visitedNodes);
                    }
                }
            } elseif (method_exists($node, 'getUsedNodes')) {
                foreach ($node->getUsedNodes() as $subnode) {
                    $this->_iterateOverNodes($subnode, $callback, $visitedNodes);
                }
            }
        }
    }

    protected function buildFromString($grammar, $options)
    {
        if (!isset($options['errorTrack'])) {
            $options['trackError'] = true;
        }
        $this->options = $options;
        $options['parser'] = $this;
        $this->grammar = \ParserGenerator\GrammarParser::getInstance()->buildGrammar($grammar, $options);
    }

    public function __construct($grammar, $options = array())
    {
        if (is_array($grammar)) {
            $this->buildFromArray($grammar, $options);
        } else {
            $this->buildFromString($grammar, $options);
        }

        /*$that = $this;
        $this->iterateOverNodes(function($node) use ($that) {
            if ($node instanceof \ParserGenerator\GrammarNode\BranchInterface && empty($that->grammar[$node->getNodeName()])) {
                $that->grammar[$node->getNodeName()] = $node;
            }
        });*/
    }

    public function parse($string, $nodeToParseName = 'start')
    {
        $this->iterateOverNodes(function ($node) {
            if ($node instanceof \ParserGenerator\GrammarNode\ErrorTrackDecorator) {
                $node->reset();
            } elseif (isset($node->lastMatch)) {
                $node->lastMatch = -1;
                $node->lastNMatch = -1;
            }
        });
        $this->cache = array();
        $restrictedEnd = array();
        if (!empty($this->options['ignoreWhitespaces'])) {
            $trimmedString = ltrim($string);
            $beforeContent = substr($string, 0, strlen($string) - strlen($trimmedString));
            $string = $trimmedString;
        } else {
            $beforeContent = '';
        }

        for ($i = strlen($string) - 1; $i > -1; $i--) {
            $restrictedEnd[$i] = $i;
        }
        $rparseResult = $this->grammar[$nodeToParseName]->rparse($string, 0, $restrictedEnd);

        if ($rparseResult) {
            $result = \ParserGenerator\SyntaxTreeNode\Root::createFromPrototype($rparseResult['node']);
            $result->setBeforeContent($beforeContent);

            return $result;
        } else {
            return false;
        }
    }

    public function getError()
    {
        $maxMatch = -1;
        $match = array();
        $this->iterateOverNodes(function ($node) use (&$maxMatch, &$match) {
            if ($node instanceof \ParserGenerator\GrammarNode\ErrorTrackDecorator) {
                if ($maxMatch < $node->getMaxCheck()) {
                    $maxMatch = $node->getMaxCheck();
                    $match = array();
                }

                if ($maxMatch === $node->getMaxCheck()) {
                    $node = $node->getDecoratedNode();

                    if ($node instanceof GrammarNode\Series) {
                        $node = $node->getMainNode();
                    }

                    $match[] = $node;
                };
            } /*elseif (isset($node->lastMatch)) {
                if ($maxMatch < $node->lastMatch) {
                    $maxMatch = $node->lastMatch;
                    $match = array();
                }

                if ($maxMatch === $node->lastMatch) {
                    if ($node instanceof GrammarNode\Series) {
                        $node = $node->getMainNode();
                    }

                    $match[] = $node;
                };
            }*/
        });

        if ($maxMatch === -1) {
            return array(
                'index' => 0,
                'expected' => array($this->grammar['start'])
            );
        }

        return array(
            'index' => $maxMatch,
            'expected' => $match
        );
    }

    public static function getLineAndCharacterFromOffset($str, $offset)
    {
        $lines = preg_split('/(\r\n|\n\r|\r|\n)/', substr($str, 0, $offset));
        return array(
            'line' => count($lines),
            'char' => strlen($lines[count($lines) - 1]) + 1
        );
    }

    public function getErrorString($str)
    {
        $error = $this->getError();

        $posData = self::getLineAndCharacterFromOffset($str, $error['index']);

        $expected = implode(' or ', $this->generalizeErrors($error['expected']));
        $foundLength = 20;
        $found = substr($str, $error['index']);
        if (strlen($found) > $foundLength) {
            $found = substr($found, 0, $foundLength) . '...';
        }

        return "line: " . $posData['line'] . ', character: ' . $posData['char'] . "\nexpected: " . $expected . "\nfound: " . $found;
    }

    public function generate($length, $node = 'start')
    {
        return GrammarStringGenerator::generate($this->grammar[$node], $length);
    }

    public function generalizeErrors($errors)
    {
        $errorsByHash = array();
        foreach ($errors as $error) {
            $errorsByHash[spl_object_hash($error)] = $error;
        }

        foreach ($errorsByHash as $hash => $error) {
            if (isset($errorsByHash[$hash]) && $error instanceof \ParserGenerator\GrammarNode\BranchInterface) {
                $this->parse('', $error->getNodeName());
                $errorData = $this->getError();
                foreach($errorData['expected'] as $errorToRemove) {
                    if ($errorToRemove !== $error) {
                        unset($errorsByHash[spl_object_hash($errorToRemove)]);
                    }
                }
            }
        }

        return array_values($errorsByHash);
    }
}
