<?php
/**
 *
 * @author Bob den Otter <bob@twokings.nl>
 */

namespace Bolt\Extension\BobdenOtter\Polls;

use Symfony\Component\HttpFoundation\Request;
use Doctrine\DBAL\Schema\Schema;

class Extension extends \Bolt\BaseExtension
{
    public function getName()
    {
        return "Polls";
    }

    public $my_table_name;

    public function initialize()
    {
        $prefix = $this->app['config']->get('general/database/prefix', "bolt_");
        $this->votes_table = $prefix . 'polls_votes';
        $this->polls_table = $this->config['tablename'];

        if ($this->app['config']->getWhichEnd() == 'backend') {
            //$this->addTables();
        } else if ($this->app['config']->getWhichEnd() == 'frontend') {
            $this->app['htmlsnippets'] = true;
            $this->addJquery();
            $this->addCss('templates/bolt_polls.css');
            $this->addJavascript('templates/bolt_polls.js');
        }

        $this->app->get("/poll_extension/fetch/{id}", array($this, 'fetch'))->bind('fetch');
        $this->app->get("/poll_extension/vote", array($this, 'vote'))->bind('vote');

    }


    public function fetch($id, Request $request)
    {

        if (is_numeric($id)) {
            $poll = $this->app['storage']->getContent($this->polls_table, array('id' => $id, 'returnsingle' => true));
        } else {
            return "no poll";
        }

        $token = $this->getToken($id);

        // Check if we've voted yet..
        $votedyet = $this->app['db']->fetchAll('SELECT * FROM ' . $this->votes_table . ' WHERE token = "' . $token . '" LIMIT 1;');


        if (rand(0,1) || !$votedyet) {

            $template_vars = array('poll' => $poll);

            return $this->render('poll_view.twig', $template_vars);

        } else {

            $temp_results = $this->app['db']->fetchAll('SELECT COUNT(vote) AS count, vote FROM ' . $this->votes_table . ' GROUP BY vote');
            $results = array('1' => 0, '2' => 0, '3' => 0, '4' => 0, '5' => 0);
            $total = 0;
            foreach($temp_results as $result) {
                $results[ $result['vote'] ] = $result['count'];
                $total += $result['count'];
            }

            $template_vars = array('poll' => $poll, 'total' => $total, 'results' => $results);

            return $this->render('poll_results.twig', $template_vars);

        }


    }


    public function vote(Request $request)
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

        // if (is_numeric($id)) {
        //     $poll = $this->app['storage']->getContent($this->polls_table, array('id' => $id, 'returnsingle' => true));
        // }

        $template_vars = array('poll' => $poll);

        return $this->fetch($poll_id, $request);

    }



    public function show_waffles(Request $request, $errors = null)
    {
        $waffles = $this->app['db']->fetchAll(
            'SELECT customer_name, num_waffles_ordered FROM ' .
            $this->my_table_name .
            ' ORDER BY id DESC LIMIT 100');
        $template_vars = array('waffles' => $waffles);
        if (is_array($errors)) {
            $template_vars['errors'] = $errors;
        }
        if ($request->getMethod() === 'POST') {
            $keys = array('customer_name', 'num_waffles_ordered');
            foreach ($keys as $key) {
                $template_vars['postData'][$key] = $request->get($key);
            }
        }

        return $this->render('waffles.twig', $template_vars);
    }

    public function clear_waffles(Request $request)
    {
        $rows_deleted = $this->app['db']->executeUpdate('DELETE FROM ' . $this->my_table_name);

        return $this->app->redirect('/waffles');
    }

    public function add_waffles(Request $request)
    {
        $customer_name = trim($request->get('customer_name'));
        $num_waffles_ordered = intval($request->get('num_waffles_ordered'));
        $errors = array();

        if (empty($customer_name)) {
            $errors['customer_name'] = 'Please provide a name';
        }
        if ($num_waffles_ordered <= 0) {
            $errors['num_waffles_ordered'] = 'You must order at least one waffle';
        }
        if ($num_waffles_ordered > 100) {
            $errors['num_waffles_ordered'] = 'Sorry, we don\'t have this many waffles';
        }

        if (empty($errors)) {
            $rows_affected = $this->app['db']->executeUpdate('INSERT INTO ' .
                $this->my_table_name .
                ' (customer_name, num_waffles_ordered) ' .
                ' VALUES (:customer_name, :num_waffles_ordered) ',
                array(
                    ':customer_name' => $customer_name,
                    ':num_waffles_ordered' => $num_waffles_ordered,
                    ));
            if ($rows_affected === 1) {
                return $this->app->redirect('/waffles');
            } else {
                $errors['general'] = 'Sorry, something went wrong';
            }
        }

        return $this->show_waffles($request, $errors);
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
        $request = Request::createFromGlobals();

        $token = sprintf("%s-%s-%s",
                $poll_id, // + time(),
                $request->getClientIp(),
                $request->server->get('HTTP_USER_AGENT')
            );

        $token = substr(md5($token), 0, 16);

        // dump($token);

        return $token;

    }


}
