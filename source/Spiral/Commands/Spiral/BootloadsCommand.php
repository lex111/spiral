<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 */
namespace Spiral\Commands\Spiral;

use Spiral\Console\Command;
use Spiral\Core\Bootloaders\Bootloader;
use Spiral\Core\BootloadManager;

/**
 * List all bootloaded libraries and classes.
 */
class BootloadsCommand extends Command
{
    /**
     * {@inheritdoc}
     */
    const NAME = 'app:bootloads';

    /**
     * {@inheritdoc}
     */
    const DESCRIPTION = 'List all bootloaded classes and libraries';

    /**
     * @param BootloadManager $bootloader
     */
    public function perform(BootloadManager $bootloader)
    {
        $grid = $this->table(['Class:', 'Booted:', 'Location:']);

        foreach ($bootloader->getClasses() as $class) {
            $reflection = new \ReflectionClass($class);

            $booted = $reflection->getConstant('BOOT') || !$reflection->isSubclassOf(Bootloader::class);

            $grid->addRow([
                $reflection->getName(),
                $booted ? 'yes' : '<info>no</info>',
                $reflection->getFileName()
            ]);
        }

        $grid->render();
    }
}