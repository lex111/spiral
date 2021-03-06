<?php
/**
 * spiral
 *
 * @author    Wolfy-J
 */

namespace Spiral\Tests\Models;

use Spiral\Models\Accessors\SqlTimestamp;
use Spiral\Tests\BaseTest;
use TestApplication\Database\SampleRecord;

class SqlTimestampsTest extends BaseTest
{
    /**
     * @expectedException \Spiral\Models\Exceptions\AccessorException
     */
    public function testErrors()
    {
        $a = new SqlTimestamp(null, []);
    }

    /**
     * @expectedException \Spiral\Models\Exceptions\AccessorException
     */
    public function testErrors2()
    {
        $this->configureDB();

        $a = new SqlTimestamp(null, ['entity' => new SampleRecord(), 'orm' => true]);
    }

    public function testSqlAccessorNotNullable()
    {
        $this->configureDB();

        $sample = new SampleRecord();
        $this->assertInstanceOf(SqlTimestamp::class, $sample->time_altered);
        $this->assertNotEmpty((string)$sample->time_altered);

        $this->assertFalse($sample->hasChanges('time_altered'));

        $sample->time_altered = 'tomorrow';
        $this->assertNotEmpty((string)$sample->time_altered);

        $this->assertTrue($sample->hasChanges('time_altered'));

        $sample->save();

        $this->assertNotEmpty($sample->primaryKey());
        $sample->touch()->save();

        //Check value in DB
        $sampleB = $this->orm->source(SampleRecord::class)->findByPK($sample->primaryKey());

        $this->assertSame(
            $sample->time_altered->getTimestamp(),
            $sampleB->time_altered->getTimestamp()
        );

        $this->assertSame(
            (string)$sample->time_altered,
            (string)$sampleB->time_altered
        );

        $this->assertInternalType('array', $sample->time_altered->__debugInfo());
    }

    public function testSqlAccessorNullable()
    {
        $this->configureDB();

        $sample = new SampleRecord();
        $this->assertNull($sample->time_nullable);
        $this->assertFalse($sample->hasChanges('time_nullable'));

        $sample->time_nullable = 'tomorrow';

        $this->assertInstanceOf(SqlTimestamp::class, $sample->time_nullable);
        $this->assertNotEmpty((string)$sample->time_altered);

        $this->assertTrue($sample->hasChanges('time_nullable'));

        $sample->save();

        $this->assertNotEmpty($sample->primaryKey());

        //Check value in DB
        $sampleB = $this->orm->source(SampleRecord::class)->findByPK($sample->primaryKey());

        $this->assertSame(
            $sample->time_nullable->getTimestamp(),
            $sampleB->time_nullable->getTimestamp()
        );

        $this->assertSame(
            (string)$sample->time_nullable,
            (string)$sampleB->time_nullable
        );
    }

    public function testAutoTimestamps()
    {
        $this->configureDB();

        $sample = new SampleRecord();
        $this->assertNull($sample->time_created);
        $this->assertNull($sample->time_updated);
        $sample->save();

        $this->assertInstanceOf(SqlTimestamp::class, $sample->time_created);
        $this->assertInstanceOf(SqlTimestamp::class, $sample->time_updated);

        $sample->value = 'abc';
        $sample->save();
    }

    protected function configureDB()
    {
        $this->commands->run('orm:schema', [
            '--alter' => true
        ]);
    }
}