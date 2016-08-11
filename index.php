<?php

/****************************************************************************

streamTV PhP / MySQL / Web Application by Cameron Cole

This Program was developed to provide access to the streamTV service database
through a web application.
*****************************************************************************/

// Set time zone  
date_default_timezone_set('America/New_York');

/****************************************************************************   
Silex Setup:
The following code is necessary for one time setup for Silex 
It uses the appropriate services from Silex and Symfony and it
registers the services to the application.
*****************************************************************************/
// Objects we use directly
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Constraints as Assert;
use Silex\Provider\FormServiceProvider;

// Pull in the Silex code stored in the vendor directory
require_once __DIR__.'/../../silex-files/vendor/autoload.php';

// Create the main application object
$app = new Silex\Application();

// For development, show exceptions in browser
$app['debug'] = true;

// For logging support
$app->register(new Silex\Provider\MonologServiceProvider(), array(
    'monolog.logfile' => __DIR__.'/development.log',
));

// Register validation handler for forms
$app->register(new Silex\Provider\ValidatorServiceProvider());

// Register form handler
$app->register(new FormServiceProvider());

// Register the session service provider for session handling
$app->register(new Silex\Provider\SessionServiceProvider());

// We don't have any translations for our forms, so avoid errors
$app->register(new Silex\Provider\TranslationServiceProvider(), array(
        'translator.messages' => array(),
    ));

// Register the TwigServiceProvider to allow for templating HTML
$app->register(new Silex\Provider\TwigServiceProvider(), array(
        'twig.path' => __DIR__.'/views',
    ));

// Change the default layout 
// Requires including boostrap.css
$app['twig.form.templates'] = array('bootstrap_3_layout.html.twig');

/*************************************************************************
 Database Connection and Queries:
 The following code creates a function that is used throughout the program
 to query the MySQL database.  This section of code also includes the connection
 to the database.  This connection only has to be done once, and the $db object
 is used by the other code.

*****************************************************************************/
// Function for making queries.  The function requires the database connection
// object, the query string with parameters, and the array of parameters to bind
// in the query.  The function uses PDO prepared query statements.

function queryDB($db, $query, $params) {
    // Silex will catch the exception
    $stmt = $db->prepare($query);
    $results = $stmt->execute($params);
    $selectpos = stripos($query, "select");
    if (($selectpos !== false) && ($selectpos < 6)) {
        $results = $stmt->fetchAll();
    }
    return $results;
}



// Connect to the Database at startup, and let Silex catch errors
$app->before(function () use ($app) {
    include '../../connect.php';
    $app['db'] = $db;
});

/*************************************************************************
 Application Code:
 The following code implements the various functionalities of the application, usually
 through different pages.  Each section uses the Silex $app to set up the variables,
 database queries and forms.  Then it renders the pages using twig.

*****************************************************************************/

// Login Page

$app->match('/login', function (Request $request) use ($app) {
	// Use Silex app to create a form with the specified parameters - username and password
	// Form validation is automatically handled using the constraints specified for each
	// parameter
    $form = $app['form.factory']->createBuilder('form')
        ->add('uname', 'text', array(
            'label' => 'User Name',
            'constraints' => array(new Assert\NotBlank())
        ))
        ->add('password', 'password', array(
            'label' => 'Password',
            'constraints' => array(new Assert\NotBlank())
        ))
        ->add('login', 'submit', array('label'=>'Login'))
        ->getForm();
    $form->handleRequest($request);

    // Once the form is validated, get the data from the form and query the database to 
    // verify the username and password are correct
    $msg = '';
    if ($form->isValid()) {
        $db = $app['db'];
        $regform = $form->getData();
        $uname = $regform['uname'];
        $pword = $regform['password'];
        $query = "select password, custID 
        			from customer
        			where username = ?";
        $results = queryDB($db, $query, array($uname));
        # Ensure we only get one entry
        if (sizeof($results) == 1) {
            $retrievedPwd = $results[0][0];
            $custID = $results[0][1];

            // If the username and password are correct, create a login session for the user
            // The session variables are the username and the customer ID to be used in 
            // other queries for lookup.
            if (password_verify($pword, $retrievedPwd)) {
                $app['session']->set('is_user', true);
                $app['session']->set('user', $uname);
                $app['session']->set('custID', $custID);
                return $app->redirect('/streamTV/');
            }
        }
        else {
        	$msg = 'Invalid User Name or Password - Try again';
        }
        
    }
    // Use the twig form template to display the login page
    return $app['twig']->render('form.html.twig', array(
        'pageTitle' => 'Login',
        'form' => $form->createView(),
        'results' => $msg
    ));
});


// *************************************************************************

// Registration Page

$app->match('/register', function (Request $request) use ($app) {
    $form = $app['form.factory']->createBuilder('form')
        ->add('uname', 'text', array(
            'label' => 'User Name',
            'constraints' => array(new Assert\NotBlank(), new Assert\Length(array('min' => 5)))
        ))
        ->add('password', 'repeated', array(
            'type' => 'password',
            'invalid_message' => 'Password and Verify Password must match',
            'first_options'  => array('label' => 'Password'),
            'second_options' => array('label' => 'Verify Password'),    
            'constraints' => array(new Assert\NotBlank(), new Assert\Length(array('min' => 5)))
        ))
        ->add('fname', 'text', array(
            'label' => 'First Name',
            'constraints' => array(new Assert\NotBlank())
        ))
        ->add('lname', 'text', array(
            'label' => 'Last Name',
            'constraints' => array(new Assert\NotBlank())
        ))
        ->add('email', 'text', array(
            'label' => 'Email',
            'constraints' => new Assert\Email()
        ))
        ->add('ccard', 'text', array(
            'label' => 'Credit Card',
            'constraints' => array(new Assert\NotBlank(), new Assert\Length(array('min' => 10)))
        ))
        ->add('submit', 'submit', array('label'=>'Register'))
        ->getForm();

    $form->handleRequest($request);

    if ($form->isValid()) {
        $regform = $form->getData();
        $uname = $regform['uname'];
        $pword = $regform['password'];
        $fname = $regform['fname'];
        $lname = $regform['lname'];
        $email = $regform['email'];
        $ccard = $regform['ccard'];
        
        
        // Check to make sure the username is not already in use
        // If it is, display already in use message
        // If not, hash the password and insert the new customer into the database
        $db = $app['db'];
        $query = 'select * from customer where username = ?';
        $results = queryDB($db, $query, array($uname));
        if ($results) {
    		return $app['twig']->render('form.html.twig', array(
        		'pageTitle' => 'Register',
        		'form' => $form->createView(),
        		'results' => 'Username already exists - Try again'
        	));
        }
        else { 
        		$query = "select RIGHT(max(c.custID),3) from customer c"; // get the ### part of the most recently inserted custID
        		$custID = queryDB($db, $query, array());
        		$newID = $custID[0][0]; // take from array as real integer
        		$newID = (integer)$newID + 1;	//add one to it
        		$newID = 'cust0' . $newID; // concatenate cust0 back on to get new custID..doesnt work for past 100.
        		
        		$membersince = date("Y-m-d");	//current date
        		$renewaldate = date("Y-m-d");   //could not figure out how to increase year by 1, used same date	
        		
			$hashed_pword = password_hash($pword, PASSWORD_DEFAULT);
			$insertData = array($uname,$hashed_pword,$fname,$lname,$email,$ccard,$newID,$membersince,$renewaldate);
       	 	$query = 'insert into customer 
        				(username, password, fname, lname, email, creditcard, custID, membersince, renewaldate)
        				values (?, ?, ?, ?, ?, ?,?,?,?)';
        	$results = queryDB($db, $query, $insertData);
	        // Maybe already log the user in, if not validating email
        	return $app->redirect('/streamTV/');
        }
    }
    return $app['twig']->render('form.html.twig', array(
        'pageTitle' => 'Register',
        'form' => $form->createView(),
        'results' => ''
    ));   
});

// *************************************************************************
 
// Actor Result Page

$app->get('/actor/{actID}', function (Silex\Application $app, $actID) {
    $db = $app['db'];
	//query for main cast information
    $query = "select distinct s.showID, s.title as title, m.role as role, a.fname as fname, a.lname as lname from shows s, main_cast m, actor a
    		where s.showID = m.showID and a.actID = m.actID and
    		a.actID = ?";
    $maincast = queryDB($db, $query, array($actID));
    
    	//query for recurring cast information
    $query = "select distinct s.showID, s.title as title, r.role as role, a.fname as fname, a.lname as lname from shows s, recurring_cast r, actor a
    		where s.showID = r.showID and a.actID = r.actID and
    		a.actID = ?";
    $reccast = queryDB($db, $query, array($actID));
    // Display results in item page
    return $app['twig']->render('actor.html.twig', array(
        'pageTitle' =>'Actor Info',
        'maincast' =>$maincast,
        'reccast' =>$reccast
    ));
});

// *************************************************************************
// Show Information Page

$app ->get('/shows/{showID}', function (Silex\Application $app, $showID) {
	$db = $app['db'];
	
	//get custID and user information from sessions variables
	if ($app['session']->get('is_user')) {
		$user = $app['session']->get('user');
		$custID = $app['session']->get('custID');
	}else{
		$user = '';
	}
	//query for general show information
	$query = "select * from shows s where s.showID = ?";
	$showinfo = queryDB($db, $query, array($showID));
	
	//query for main cast information
	$query = "select distinct m.role, a.fname, a.lname, a.actID from actor a, main_cast m, shows s 
	where s.showID = m.showID and a.actID = m.actID and s.showID = ?";
	$maininfo = queryDB($db, $query, array($showID));
	
	//query for recurring cast information
	$query = "select count(a.actID) as acount, a.fname, a.lname, a.actID, r.role from actor a, recurring_cast r, shows s, episode e 
	where e.episodeID = r.episodeID and r.showID = s.showID and e.showID = s.showID and a.actID = r.actID and s.showID = ? group by a.actID";
	$recinfo = queryDB($db, $query, array($showID));
	
	//query to see if show is in queue
	$query = "select q.showID from customer c, cust_queue q where q.custID = c.custID and q.showID = ? and c.custID = ?";
	$inqueue = queryDB($db, $query, array($showID,$custID));

	if($inqueue != null){ //if it is in queue already
	return $app['twig']->render('show.html.twig', array(
		'pageTitle' =>'Show Information',
		'showinfo' =>$showinfo,
		'maininfo' =>$maininfo,
		'recinfo' =>$recinfo,
		'inqueue' =>$inqueue,
		'user' =>$user
	));
	}else{ //if not, we need inqueue to be empty
		return $app['twig']->render('show.html.twig', array(
		'pageTitle' =>'Show Information',
		'showinfo' =>$showinfo,
		'maininfo' =>$maininfo,
		'recinfo' =>$recinfo,
		'inqueue' =>'',
		'user' =>$user
	));
	}
});

// *************************************************************************
// Add to Queue

$app ->get('/addtoqueue/{showID}', function (Silex\Application $app, $showID) {
	$db = $app['db'];
	
	//get custID and user information from sessions variables
	if ($app['session']->get('is_user')) {
		$user = $app['session']->get('user');
		$custID = $app['session']->get('custID');
	}else{
		$user = '';
	}
	
	$datequeued = date("Y-m-d"); //date queued is current date
	
	$insertData = array($custID, $showID, $datequeued);
	
	$query = 'insert into cust_queue 
        				(custID,showID,datequeued)
        				values (?, ?, ?)';
        	$results = queryDB($db, $query, $insertData);
	        // Return to home page
        	return $app->redirect('/streamTV/');
 
    return $app['twig']->render('form.html.twig', array(
        'pageTitle' => 'AddtoQueue',
        'form' => $form->createView(),
        'results' => ''
    ));   
});
	

// *************************************************************************
// Show Episodes Page

$app ->get('/show_episodes/{showID}', function (Silex\Application $app, $showID) {
	$db = $app['db'];
	
	$query = "select s.showID as shownum, s.title as stitle, e.title as etitle, e.airdate, e.episodeID, LEFT(e.episodeID,1) as season from episode e, shows s 
	where e.showID = s.showID and s.showID = ? order by e.airdate";
	$result = queryDB($db, $query, array($showID));
	
	return $app['twig']->render('showepisode.html.twig', array(
		'pageTitle' =>'Show Episodes',
		'result' =>$result
	));
});

// *************************************************************************
// Episode Info Page

$app ->get('/episodeinfo/{showID}&{episodeID}', function (Silex\Application $app, $showID, $episodeID) {
	$db = $app['db'];
	
	//get custID and user information from sessions variables
	if ($app['session']->get('is_user')) {
		$user = $app['session']->get('user');
	}else{
		$user = '';
	}
	
	$query = "select s.showID, s.title as stitle, e.title as etitle, e.airdate as adate, e.episodeID from shows s, episode e 
	where s.showID = e.showID and s.showID = ? and e.episodeID = ?";
	$sresult = queryDB($db, $query, array($showID,$episodeID));
	
	$query = "select distinct m.role, a.fname, a.lname, a.actID AS actnum from actor a, main_cast m, shows s 
	where s.showID = m.showID and a.actID = m.actID and s.showID = ?";
	$mresult = queryDB($db, $query, array($showID));
	
	$query = "select distinct r.role, a.fname, a.lname, a.actID AS actnum from actor a, recurring_cast r, shows s, episode e 
	where s.showID = r.showID and a.actID = r.actID and r.episodeID = e.episodeID and s.showID = ? and e.episodeID = ?";
	$recresult = queryDB($db, $query, array($showID,$episodeID));
	
	return $app['twig']->render('episodeinfo.html.twig', array(
		'pageTitle' =>'Episode Info',
		'sresult' =>$sresult,
		'mresult' =>$mresult,
		'recresult' =>$recresult,
		'user' =>$user
	));
});

// *************************************************************************
// Search Result Page

$app->match('/search', function (Request $request) use ($app) {
    $form = $app['form.factory']->createBuilder('form')
        ->add('search', 'text', array(
            'label' => 'Search',
            'constraints' => array(new Assert\NotBlank())
        ))
        ->getForm();
    $form->handleRequest($request);
    if ($form->isValid()) {
        $regform = $form->getData();
		$srch = $regform['search'];
		
		// Create prepared query for show titles
        $db = $app['db'];
		$query = "SELECT title, showID FROM shows where title like ?";
		$results = queryDB($db, $query, array('%'.$srch.'%'));
		
		// Create prepared query for actor names
		$query = "(SELECT fname, lname, actID FROM actor where lname like ?) UNION (SELECT fname, lname, actID FROM actor where fname like ?)" ;
		$results2 = queryDB($db, $query, array('%'.$srch.'%', '%'.$srch.'%'));
		
        // Display results in search page
        return $app['twig']->render('search.html.twig', array(
            'pageTitle' => 'Search',
            'form' => $form->createView(),
            'results' => $results,
            'results2' => $results2
        ));
    }
    // If search box is empty, redisplay search page
    return $app['twig']->render('search.html.twig', array(
        'pageTitle' => 'Search',
        'form' => $form->createView(),
        'results' => '',
        'results2' => ''
    ));
});

// *************************************************************************
// Queued

$app->match('/queue', function() use ($app) {
	// Get session variables
	$result='';
	//get custID and user information from sessions variables
	if ($app['session']->get('is_user')) {
		$user = $app['session']->get('user');
		$custID = $app['session']->get('custID');
		
        $db = $app['db'];
        
        //find all queued shows for user with custID
		$query = "select s.title, s.showID, c.fname, c.lname, c.email, q.datequeued from shows s, customer c, cust_queue q 
		where s.showID = q.showID and c.custID = q.custID and c.custID = ?";
		$result= queryDB($db, $query, array($custID));

	}
	
	return $app['twig']->render('queue.html.twig', array(
		'pageTitle' => 'Queue',
		'result' => $result
	));
});

// *************************************************************************
// Watched Info Page

$app ->get('/watched/{showID}', function (Silex\Application $app, $showID) {
	$db = $app['db'];
	
	$result = '';
	//get custID and user information from sessions variables
	if ($app['session']->get('is_user')) {
		$user = $app['session']->get('user');
		$custID = $app['session']->get('custID');
		
	$query = "select s.showID, s.title as stitle, c.fname, c.lname, e.episodeID, e.title as etitle, max(w.datewatched) as datewatched
	 from shows s, customer c, episode e, watched w 
	where w.custID = c.custID and w.showID = s.showID and w.episodeID = e.episodeID and e.showID = s.showID and s.showID = ? and c.custID = ? 
	group by e.episodeID order by w.datewatched";
	$result = queryDB($db, $query, array($showID,$custID));
	
	}
	return $app['twig']->render('watched.html.twig', array(
		'pageTitle' =>'Watched Info',
		'result' =>$result
	));
});

// *************************************************************************
// Watching Page

$app ->get('/watch_episode/{showID}&{episodeID}', function (Silex\Application $app, $showID, $episodeID) {
	$db = $app['db'];
	$result = '';
	//get custID and user information from sessions variables
	if ($app['session']->get('is_user')) {
		$user = $app['session']->get('user');
		$custID = $app['session']->get('custID');
	}
	
	//find last time episode was watched
	$query = "select max(w.datewatched) from watched w
	where w.showID = ? and w.episodeID = ? and w.custID = ?";
	
	$datewatched = queryDB($db,$query, array($showID, $episodeID, $custID));
	$datewatched = $datewatched[0][0]; // extract date value
	
	$currentdate = date("Y-m-d"); //current date
	
	if($currentdate != $datewatched){ //compare current date and query result
	
		$insertData = array($custID, $currentdate, $episodeID, $showID);
       		$query = 'insert into watched 
        		(custID, datewatched, episodeID, showID)
        			values (?, ?, ?, ?)';
        	$result = queryDB($db, $query, $insertData);
  
	}
	
	$query = "select s.title as stitle, e.title as etitle from shows s, episode e 
	where e.showID = s.showID and s.showID = ? and e.episodeID = ?";
	$info = queryDB($db,$query, array($showID, $episodeID));
	      
        return $app['twig']->render('watching.html.twig', array(
		'pageTitle' =>'Watching',
		'result' =>$result,
		'info' =>$info
		
	));
});
	

// *************************************************************************

// Logout

$app->get('/logout', function () use ($app) {
	$app['session']->clear();
	return $app->redirect('/streamTV/');
});
	
// *************************************************************************

// Home Page

$app->get('/', function () use ($app) {
	if ($app['session']->get('is_user')) {
		$user = $app['session']->get('user');
	}
	else {
		$user = '';
	}
	return $app['twig']->render('home.twig', array(
        'user' => $user,
        'pageTitle' => 'Home'));
});

// *************************************************************************

// Run the Application

$app->run();