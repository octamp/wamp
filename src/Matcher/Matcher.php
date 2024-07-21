<?php

declare(strict_types=1);

namespace Octamp\Wamp\Matcher;

class Matcher
{
    /**
     * @var MatchInterface[]
     */
    protected array $matches = [];

    public function __construct()
    {
        $this->addMatch(new ExactMatch());
    }

    public function addMatch(MatchInterface $match): void
    {
        $this->matches[$match->getName()] = $match;
    }

    public function isMatch(string $from, string $to, string $name): bool
    {
        if (!isset($this->matches[$name])) {
            return false;
        }

        return $this->matches[$name]->isMatched($from, $to);
    }

    /**
     * @throws UnExistMatchException
     */
    public function getMatch($name): MatchInterface
    {
        if (!isset($this->matches[$name])) {
            throw new UnExistMatchException('Matcher ' . $name . ' does not exists');
        }

        return $this->matches[$name];
    }
}