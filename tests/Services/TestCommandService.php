<?php
/**
 * @author Ben Kuhl <bkuhl@indatus.com>
 */

use Indatus\Dispatcher\Drivers\Cron\ScheduleService;
use Indatus\Dispatcher\Queue;
use Indatus\Dispatcher\Scheduler;
use Indatus\Dispatcher\Services\CommandService;
use Indatus\Dispatcher\Table;
use Mockery as m;

class TestCommandService extends TestCase
{
    /**
     * @var Indatus\Dispatcher\Services\CommandService
     */
    private $commandService;

    public function setUp()
    {
        parent::setUp();

        $scheduleService = new ScheduleService(new Table(), new Queue());
        $this->commandService = new CommandService($scheduleService);

    }

    public function tearDown()
    {
        parent::tearDown();
        m::close();
    }

    public function testRunDue()
    {
        $scheduledCommand = $this->mockCommand();
        $scheduledCommand->shouldReceive('isEnabled')->once()->andReturn(true);
        $scheduledCommand->shouldReceive('getName')->once()->andReturn('test:command');
        $scheduledCommand->shouldReceive('user')->once()->andReturn(false);

        $scheduler = m::mock('Indatus\Dispatcher\Scheduling\Schedulable');
        $scheduler->shouldReceive('getArguments')->once()->andReturn(array());
        $scheduler->shouldReceive('getOptions')->once()->andReturn(array());
        $queue = m::mock('Indatus\Dispatcher\Queue',
            function ($m) use ($scheduledCommand, $scheduler) {
                $item = m::mock('Indatus\Dispatcher\QueueItem');
                $item->shouldReceive('getCommand')->once()->andReturn($scheduledCommand);
                $item->shouldReceive('getScheduler')->once()->andReturn($scheduler);
                $m->shouldReceive('flush')->once()->andReturn(array($item));
            });
        $scheduleService = m::mock('Indatus\Dispatcher\Drivers\Cron\ScheduleService');
        $scheduleService->shouldReceive('getQueue')->once()->andReturn($queue);
        $this->app->instance('Indatus\Dispatcher\Services\ScheduleService', $scheduleService);

        $commandService = m::mock('Indatus\Dispatcher\Services\CommandService[runnableInEnvironment,run]',
            array($scheduleService),
            function ($m) use ($scheduledCommand, $scheduler) {
                $m->shouldReceive('runnableInEnvironment')->andReturn(true);
                $m->shouldReceive('run')->with($scheduledCommand, $scheduler)->andReturnNull();
            });

        $this->assertNull($commandService->runDue());
    }

    public function testRunnableInAnyEnvironment()
    {
        $scheduledCommand = $this->mockCommand('*');

        App::shouldReceive('environment')->andReturn('*');
        $this->assertTrue($this->commandService->runnableInEnvironment($scheduledCommand));
    }

    public function testRunnableInOneEnvironment()
    {
        $scheduledCommand = $this->mockCommand('local');

        App::shouldReceive('environment')->andReturn('local');
        $this->assertTrue($this->commandService->runnableInEnvironment($scheduledCommand));
    }

    public function testRunnableInMultipleEnvironments()
    {
        $scheduledCommand = $this->mockCommand(array('local', 'development'));

        App::shouldReceive('environment')->andReturn('local');
        $this->assertTrue($this->commandService->runnableInEnvironment($scheduledCommand));
    }

    public function testNotRunnableInEnvironment()
    {
        $scheduledCommand = $this->mockCommand(array('local', 'development'));

        App::shouldReceive('environment')->andReturn('amazonAWS');
        $this->assertFalse($this->commandService->runnableInEnvironment($scheduledCommand));
    }

    public function testPrepareArguments()
    {
        $arguments = array(
            'argument'
        );
        $commandService = $this->getMockForAbstractClass('Indatus\Dispatcher\Services\CommandService',
            array(
                m::mock('Indatus\Dispatcher\Services\ScheduleService')
            ));

        $this->assertEquals(
            'argument',
            $commandService->prepareArguments($arguments)
        );
    }

    public function testPrepareOptions()
    {
        $arguments = array(
            'test' => 'argument',
            'keyOnly'
        );
        $commandService = $this->getMockForAbstractClass('Indatus\Dispatcher\Services\CommandService',
            array(
                m::mock('Indatus\Dispatcher\Services\ScheduleService')
            ));

        $this->assertEquals(
            '--test="argument" --keyOnly',
            $commandService->prepareOptions($arguments)
        );
    }

    public function testPrepareOptionsArrayValue()
    {
        $arguments = array(
            'test' => 'argument',
            'option' => array(
                'value1',
                'value2'
            )
        );
        $commandService = $this->getMockForAbstractClass('Indatus\Dispatcher\Services\CommandService',
            array(
                m::mock('Indatus\Dispatcher\Services\ScheduleService')
            ));

        $this->assertEquals(
            '--test="argument" --option="value1" --option="value2"',
            $commandService->prepareOptions($arguments)
        );
    }

    public function testGetRunCommand()
    {
        $commandName = 'test:command';
        $scheduledCommand = $this->mockCommand();
        $scheduledCommand->shouldReceive('getName')->andReturn($commandName);
        $scheduledCommand->shouldReceive('user')->andReturn(false);
        $this->assertEquals($this->commandService->getRunCommand($scheduledCommand), implode(' ', array(
                    'php',
                    base_path().'/artisan',
                    $commandName,
                    '&',
                    '> /dev/null 2>&1'
                )));
    }

    public function testGetRunCommandWithArguments()
    {
        $commandName = 'test:command';
        $scheduledCommand = $this->mockCommand();
        $scheduledCommand->shouldReceive('getName')->andReturn($commandName);
        $scheduledCommand->shouldReceive('user')->andReturn(false);
        $this->assertEquals(
            $this->commandService->getRunCommand(
                $scheduledCommand,
                array(
                    'option'
                )
            ),
            implode(' ', array(
                    'php',
                    base_path().'/artisan',
                    $commandName,
                    'option',
                    '&',
                    '> /dev/null 2>&1'
                )));
    }

    public function testGetRunCommandWithOptions()
    {
        $commandName = 'test:command';
        $scheduledCommand = $this->mockCommand();
        $scheduledCommand->shouldReceive('getName')->andReturn($commandName);
        $scheduledCommand->shouldReceive('user')->andReturn(false);
        $this->assertEquals(
            $this->commandService->getRunCommand(
                $scheduledCommand,
                array(),
                array(
                    'option' => 'value',
                    'anotherOption'
                )
            ),
            implode(' ', array(
                    'php',
                    base_path().'/artisan',
                    $commandName,
                    '--option="value"',
                    '--anotherOption',
                    '&',
                    '> /dev/null 2>&1'
                )));
    }

    public function testGetRunCommandAsUser()
    {
        $user = 'myUser';
        $commandName = 'test:command';
        $scheduledCommand = $this->mockCommand();
        $scheduledCommand->shouldReceive('getName')->andReturn($commandName);
        $scheduledCommand->shouldReceive('user')->andReturn($user);
        $this->assertEquals($this->commandService->getRunCommand($scheduledCommand), implode(' ', array(
                    'sudo -u '.$user,
                    'php',
                    base_path().'/artisan',
                    $commandName,
                    '&',
                    '> /dev/null 2>&1'
                )));
    }

    private function mockCommand ($environment = '*')
    {
        return $class = m::mock('Indatus\Dispatcher\Scheduling\ScheduledCommand', function ($m) use ($environment) {
                $m->shouldReceive('environment')->andReturn($environment);
            });
    }

} 