<?php

/**
 * This file is part of the FazlandElasticaBundle project.
 *
 * (c) Infinite Networks Pty Ltd <http://www.infinite.net.au>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Fazland\ElasticaBundle\Tests\Functional;

class TypeObj
{
    public $id = 5;
    public $coll;
    public $field1;
    public $field2;
    public $field3;

    public function isIndexable()
    {
        return true;
    }

    public function isntIndexable()
    {
        return false;
    }

    public function getSerializableColl()
    {
        if (null === $this->coll) {
            return null;
        }

        return iterator_to_array($this->coll, false);
    }
}
