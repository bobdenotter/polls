<?php
/**
 *
 * @author Bob den Otter <bob@twokings.nl>
 */

namespace Bolt\Extension\BobdenOtter\Polls;

use Doctrine\DBAL\Schema\Schema;

class Extension extends \Bolt\BaseExtension
{
    public function getName()
    {
        return "Polls";
    }

    public function isSafe()
    {
        return true;
    }

    public $my_table_name;

    public function initialize()
    {
        $prefix = $this->app['config']->get('general/database/prefix', "bolt_");
        $this->votes_table = $prefix . 'polls_votes';
        $this->polls_table = $this->config['tablename'];

        if ($this->app['config']->getWhichEnd() == 'backend') {
            $this->addTables();
        }

        // Whether or not to add our own snippets.
        $this->app['htmlsnippets'] = true;

        $this->app->get("/poll_extension/fetch/{id}", array($this, 'fetch'))->bind('fetch');
        $this->app->get("/poll_extension/vote", array($this, 'vote'))->bind('vote');

        $this->addTwigFunction('bolt_poll', 'embed', array('safe' => 'html'));

    }

    public function embed($id, $options = array())
    {
        $html = $this->fetch($id, $options);

        return new \Twig_Markup($html, 'UTF-8');
    }


    public function fetch($id, $options = array())
    {

        if (is_numeric($id)) {
            $poll = $this->app['storage']->getContent($this->polls_table, array('id' => $id, 'returnsingle' => true));
        } else {
            return "no poll";
        }

        if ($this->app['htmlsnippets']) {
            if (!empty($this->config['css'])) {
                $this->addCss('templates/bolt_polls.css');
            }
            if (!empty($this->config['javascript'])) {
                $this->addJquery();
                $this->addJavascript('templates/bolt_polls.js', true);
                $this->addSnippet('aftermeta', '<script>var boltpolls_url = "' . $this->app['paths']['root'] . 'poll_extension/vote";</script>');
        }   }

        $token = $this->getToken($id);

        // Check if we've voted yet..
        $votedyet = $this->app['db']->fetchAll('SELECT * FROM ' . $this->votes_table . ' WHERE token = "' . $token . '" LIMIT 1;');

        if (!$votedyet) {

            $template_vars = array('poll' => $poll, 'options' => $options);

            return $this->render('poll_view.twig', $template_vars);

        } else {

            $temp_results = $this->app['db']->fetchAll('SELECT COUNT(vote) AS count, vote FROM ' . $this->votes_table . ' GROUP BY vote');
            $results = array('1' => 0, '2' => 0, '3' => 0, '4' => 0, '5' => 0);
            $total = 0;
            foreach($temp_results as $result) {
                $results[ $result['vote'] ] = $result['count'];
                $total += $result['count'];
            }

            $template_vars = array('poll' => $poll, 'total' => $total, 'results' => $results, 'options' => $options);

            return $this->render('poll_results.twig', $template_vars);

        }


    }


    public function vote()
    {
        list($poll_id, $vote) = explode("_", $this->app['request']->get('vote'));

        if (is_numeric($poll_id) && is_numeric($vote)) {

            $poll = $this->app['storage']->getContent($this->polls_table, array('id' => $poll_id, 'returnsingle' => true));

            $token = $this->getToken($poll_id);

            $this->app['db']->executeQuery('DELETE FROM ' . $this->votes_table . ' WHERE token = "' . $token . '" LIMIT 1;');
            $this->app['db']->executeUpdate('INSERT INTO ' . $this->votes_table .
                ' (token, poll_id, vote) ' .
                ' VALUES (:token, :poll_id, :vote) ',
                array(
                    ':token' => $token,
                    ':poll_id' => intval($poll_id),
                    ':vote' => intval($vote)
                    ));
        }

        $this->app['htmlsnippets'] = false;


        return $this->fetch($poll_id);

    }



    private function render($template, $data)
    {
        $this->app['twig.loader.filesystem']->addPath(dirname(__FILE__) . '/templates');

        return $this->app['render']->render($template, $data);
    }

    private function addTables()
    {

        $me = $this;
        $this->app['integritychecker']->registerExtensionTable(
            function (Schema $schema) use ($me) {
                $table = $schema->createTable($me->votes_table);
                $table->addColumn("id", "integer", array('autoincrement' => true));
                $table->setPrimaryKey(array("id"));
                $table->addColumn("token", "string", array("length" => 16));
                $table->addColumn("poll_id", "integer");
                $table->addColumn("vote", "integer");
                return $table;
            });

    }

    private function getToken($poll_id = '')
    {
        // $request = Request::createFromGlobals();

        $token = sprintf("%s-%s-%s-%s",
                $poll_id,
                $this->app['request']->getClientIp(),
                $this->app['request']->server->get('HTTP_USER_AGENT'),
                $this->config['allowcheats'] ? time() : ''
            );

        $token = substr(md5($token), 0, 16);

        // dump($token);

        return $token;

    }


}
