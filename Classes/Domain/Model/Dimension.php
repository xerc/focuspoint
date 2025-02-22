<?php

declare(strict_types=1);

/**
 * Dimension.
 */

namespace HDNET\Focuspoint\Domain\Model;

use HDNET\Autoloader\Annotation\DatabaseField;
use HDNET\Autoloader\Annotation\DatabaseTable;
use HDNET\Autoloader\Annotation\SmartExclude;

/**
 * Dimension.
 *
 * @DatabaseTable
 * @SmartExclude({"EnableFields", "Language"})
 */
class Dimension extends AbstractModel
{
    /**
     * Title.
     *
     * @var string
     * @DatabaseField(type="string")
     */
    protected $title;

    /**
     * Identifier.
     *
     * @var string
     * @DatabaseField(type="string")
     */
    protected $identifier;

    /**
     * Dimension.
     *
     * @var string
     * @DatabaseField(type="string")
     */
    protected $dimension;

    /**
     * @return mixed
     */
    public function getTitle()
    {
        return $this->title;
    }

    /**
     * @param mixed $title
     */
    public function setTitle($title): void
    {
        $this->title = $title;
    }

    /**
     * @return mixed
     */
    public function getIdentifier()
    {
        return $this->identifier;
    }

    /**
     * @param mixed $identifier
     */
    public function setIdentifier($identifier): void
    {
        $this->identifier = $identifier;
    }

    /**
     * @return mixed
     */
    public function getDimension()
    {
        return $this->dimension;
    }

    /**
     * @param mixed $dimension
     */
    public function setDimension($dimension): void
    {
        $this->dimension = $dimension;
    }
}
