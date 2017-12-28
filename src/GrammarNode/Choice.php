<?php declare(strict_types=1);

namespace ParserGenerator\GrammarNode;

class Choice extends \ParserGenerator\GrammarNode\BaseNode
{
    protected $choices;
    protected $tmpNodeName;
    protected $reduce = [];

    public function __construct($choices)
    {
        $this->choices = $choices;
        $this->tmpNodeName = '&choices/' . spl_object_hash($this);

        $this->grammarNode = new \ParserGenerator\GrammarNode\Branch($this->tmpNodeName);

        $node = [];
        foreach ($choices as $choice) {
            if (is_array($choice)) {
                $node[] = $choice;
                $this->reduce[] = false;
            } else {
                $node[] = [$choice];
                $this->reduce[] = true;
            }
        };

        $this->grammarNode->setNode($node);
    }

    public function rparse($string, $fromIndex = 0, $restrictedEnd = [])
    {
        if ($rparseResult = $this->grammarNode->rparse($string, $fromIndex, $restrictedEnd)) {
            if ($this->reduce[$rparseResult['node']->getDetailType()]) {
                $rparseResult['node'] = $rparseResult['node']->getSubnode(0);
            }

            return $rparseResult;
        }

        return false;
    }

    public function getTmpNodeName()
    {
        return $this->tmpNodeName;
    }

    public function getNode()
    {
        return $this->grammarNode->getNode();
    }

    public function setParser(\ParserGenerator\Parser $parser)
    {
        $this->parser = $parser;
        $this->grammarNode->setParser($parser);
    }

    public function __toString()
    {
        $result = '';
        foreach ($this->choices as $choice) {
            $result .= ($result ? '|' : '') . (is_array($choice) ? implode(" ", $choice) : $choice);
        }

        return '(' . $result . ')';
    }

    public function copy($copyCallback)
    {
        $copy = new static($copyCallback($this->choices));
        $copy->setParser($this->parser);
        return $copy;
    }
}
