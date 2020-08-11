<?php

namespace App\Controller;

use App\Controller\AppController;
use Cake\Mailer\Email;
use Cake\Routing\Router;
use Cake\Http\Exception\NotFoundException;
use Cake\ORM\TableRegistry;
use Cake\ORM\Query;
use Cake\Http\Exception\MethodNotAllowedException;

/**
 * Users Controller
 *
 * @property \App\Model\Table\UsersTable $Users
 *
 * @method \App\Model\Entity\User[]|\Cake\Datasource\ResultSetInterface paginate($object = null, array $settings = [])
 */
class UsersController extends AppController
{
    /**
     * Set authorization
     * @param array $user 
     * @return bool 
     */
    public function isAuthorized($user)
    {
        return true;
    }

    /**
     * Initialize method
     *
     * @return \Cake\Http\Response|null
     */
    public function initialize()
    {
        parent::initialize();
        $this->Auth->allow(['register', 'resendMail', 'activate', 'logout']);
        $action = $this->request->getParam('action');
        if (in_array($action, ['register', 'activate', 'login'])) {
            if ($this->Auth->user()) {
                return $this->redirect($this->Auth->redirectUrl());
            }
        }
    }

    /**
     * Before filter unlocked Actions
     *
     * @return \Cake\Http\Response|null
     * @param \Cake\Event\Event $event
     */
    public function beforeFilter(\Cake\Event\Event $event)
    {
        parent::beforeFilter($event);

        $action = $this->request->getParam('action');
        if (in_array($action, ['userPage'])) {
            $id = $this->request->getParam('pass.0');
            if (!$id) {
                return $this->redirect($this->referer());
            }
        }

        $this->Security->setConfig('unlockedActions', ['fetchUserPost', 'fetchSearch']);
    }

    /**
     * Login Function
     *
     * @return \Cake\Http\Response|null
     */
    public function login()
    {
        $this->viewBuilder()->setLayout('front');

        if ($this->request->is('post')) {
            $user = $this->Auth->identify();

            if ($user) {
                if ($user['deleted']) {
                    $this->Flash->error(__('Incorrect username or password.'));
                } elseif (!$user['activated']) {
                    $this->Flash->error(__('Cannot Login. User not yet activated.'));
                } else {
                    $this->Auth->setUser($user);
                    return $this->redirect($this->Auth->redirectUrl());
                }
            } else {
                $this->Flash->error(__('Incorrect username or password.'));
            }
        }
        $this->set(['login' => true]);
    }

    /**
     * Logout Function
     *
     * @return \Cake\Http\Response|null
     */
    public function logout()
    {
        return $this->redirect($this->Auth->logout());
    }

    /**
     * User Registration
     *
     * @return \Cake\Http\Response|null
     */
    public function register()
    {
        $this->viewBuilder()->setLayout('front');

        $newUser = $this->Users->newEntity();
        $user = [];

        if ($this->request->is(['post'])) {
            $user = $this->Users->patchEntity($newUser, $this->request->getData());
            $user->activation_code = substr(md5(microtime()), rand(0, 26), 8);
            if ($this->Users->save($user)) {
                //Sending Email
                if ($this->sendEmail($user)) {
                    $this->Flash->success(__('Successfully Registered! Please check your email for activation link.'));

                    return $this->redirect([
                        'controller' => 'users',
                        'action' => 'register'
                    ]);
                } else {
                    $this->Flash->error(__('Registration complete but email failed to send...'));
                    $this->set(['userResend' => $user]);
                }
            }
        }

        $this->set(['newUser' => $newUser]);
    }

    /**
     * Sending email
     *
     * @return bool
     * @param object $user
     */
    public function sendEmail($user)
    {
        try {
            $link = Router::url('/', true) . 'users/activate/' . $user->username;
            $email = new Email('gmail');
            $email->from(['microblog.grace@gmail.com' => 'Microblog 3']);
            $email->to($user->email);
            $email->subject('Activate User Account');
            $email->template('verification', 'verification_layout');
            $email->emailFormat('html');
            $email->viewVars([
                'name' =>  $user->username,
                'code' => $user->activation_code,
                'verification_link' => $link . '/' . $user->activation_code
            ]);

            return $email->send();
        } catch (\Exception $e) {
            //for debugging
            //$this->Flash->error(__($e->getMessage()));
            return false;
        }
    }

    /**
     * Resending email
     *
     * @return \Cake\Http\Response|null
     * @param string|null $id User id
     */
    public function resendMail($id = null)
    {
        $this->autoRender = false;

        $userquery = $this->Users->find('all', [
            'conditions' => ['Users.id' => (int) $id]
        ]);
        $newUser = $userquery->first();

        if ($this->request->is(['post', 'put'])) {
            $user = $this->Users->patchEntity($newUser, $this->request->getData());
            if ($this->sendEmail($user)) {
                $this->Flash->success(__('Successfully Send to Email. Please activate your account.'));
            } else {
                $this->Flash->error(__('Email still not send due to error, Please contact the admin to verify your account.'));
            }
        }
        return $this->redirect([
            'controller' => 'users',
            'action' => 'register'
        ]);
    }

    /**
     * User Activation
     *
     * @return \Cake\Http\Response|null
     * @param string $username, string $activationCode
     */
    public function activate($username = null, $activationCode = null)
    {
        $conditions = [
            'username' => $username,
            'activation_code' => $activationCode,
            'deleted' => false
        ];
        if ($this->Users->exists($conditions)) {
            $userQuery = $this->Users->find('all', [
                'conditions' => $conditions
            ]);
            $user = $userQuery->first();
            // check if already activated
            if (!$user->activated) {
                $user->set('activated', true);
                if ($this->Users->save($user)) {
                    $this->Flash->success(__('Successfully activated the user'));
                    $this->Auth->setUser($user);
                    return $this->redirect($this->Auth->redirectUrl());
                }
            } else {
                $this->Flash->error(__('User already activated'));
            }
        } else {
            $this->Flash->error(__('Activation Failed, No match in record.'));
        }
        return $this->redirect([
            'controller' => 'users',
            'action' => 'register'
        ]);
    }

    /**
     * Retrieve View of Account Settings
     *
     * @return \Cake\Http\Response|null
     */
    public function accountSettings()
    {
        $this->viewBuilder()->setLayout('inside');
        $updateUser = $this->Users->get($this->Auth->user('id'), [
            'contain' => [],
        ]);

        $this->set(['updateUser' => $updateUser]);
    }

    /**
     * Updating User
     * @throws Cake\Http\Exception\MethodNotAllowedException
     * @return JsonResponse|null
     */
    public function updateUser()
    {
        try {
            $this->autoRender = false;
            if (!$this->request->is('ajax')) {
                throw new MethodNotAllowedException('Not allowed to access');
            }
            $returnMessage = [];
            $user = $this->Users->get($this->Auth->user('id'), [
                'contain' => [],
            ]);
            if ($this->request->is(['patch', 'post', 'put'])) {
                $user = $this->Users->patchEntity($user, $this->request->getData());
                if ($this->Users->save($user)) {
                    $this->Auth->setUser($user);

                    $returnMessage['success'] = 'Successfully updated the user!';
                    $this->Flash->success(__($returnMessage['success']));
                    return $this->json($returnMessage);
                } else {
                    $returnMessage['error'] = 'Unable to save user.';
                    $returnMessage['errorList'] = $user->errors();
                    return $this->json($returnMessage);
                }
            }
        } catch (MethodNotAllowedException $e) {
            $this->Flash->error(__($e->getMessage()));
            return $this->redirect(['controller' => 'posts', 'action' => 'index']);
        }
    }

    /**
     * Rendering User Page
     *
     * @return view or redirect Page
     * @param string|null $id User id
     * @throws \Cake\Http\Exception\NotFoundException
     */
    public function userPage($id = null)
    {
        try {

            if (!$id) {
                throw new NotFoundException(__('No reference user'));
            }

            $this->viewBuilder()->setLayout('inside');

            $queryUser = $this->Users->find('all', [
                'contain' => [],
                'conditions' => [
                    'Users.id' => (int) $id,
                    'Users.deleted' => false,
                    'Users.activated' => true
                ]
            ]);

            $user = $queryUser->first();

            if (empty($user)) {
                throw new NotFoundException(__('Invalid user'));
            }

            $followed = true;
            $followers = TableRegistry::getTableLocator()->get('Followers');

            //Check if current authenticated user follow the user
            $queryFollower = $followers->find(
                'all',
                [
                    'conditions' => [
                        'Followers.user_id' => $this->Auth->user('id'),
                        'Followers.following_user_id' => (int) $id
                    ]
                ]
            );

            $follower = $queryFollower->first();

            if (!$follower) {
                $followed = false;
            } else if ($follower->deleted) {
                $followed = false;
            }

            //Getting the updated list of first 5 recent followers
            $followerListQuery = $followers->find('all')
                ->contain(['Users' => function (Query $q) {
                    return $q->select(['image', 'username', 'id']);
                }])
                ->order(['Followers.modified' => 'DESC'])
                ->where([
                    'Followers.deleted' => false,
                    'Followers.following_user_id' => (int) $id
                ])
                ->limit(5);

            $followerList = $followerListQuery->all();

            //Getting the updated list of first 5 recent followings
            $followingListQuery = $followers->find('all')
                ->contain(['FollowingUsers' => function (Query $q) {
                    return $q->select(['image', 'username', 'id']);
                }])
                ->order(['Followers.modified' => 'DESC'])
                ->where([
                    'Followers.deleted' => false,
                    'Followers.user_id' => (int) $id
                ])
                ->limit(5);

            $followingList = $followingListQuery->all();

            //if authenticated user followed the user
            $user->followed = $followed;

            $posts = TableRegistry::getTableLocator()->get('Posts');

            $newPost = $posts->newEntity();

            $this->set([
                'user' => $user,
                'newPost' => $newPost,
                'followers' => $followerList,
                'followings' => $followingList
            ]);
        } catch (NotFoundException $e) {
            $this->Flash->error(__($e->getMessage()));
            return $this->redirect($this->referer());
        }
    }

    /**
     * Retrieving User Post for User Page
     * @throws Cake\Http\Exception\MethodNotAllowedException
     * @return ajax view
     */
    public function fetchUserPost()
    {
        try {
            $this->viewBuilder()->setLayout('ajax');
            if (!$this->request->is('ajax')) {
                throw new MethodNotAllowedException('Not allowed to access');
            }
            if ($this->request->is(['post'])) {
                if (
                    isset($this->request->getData()['limit']) &&
                    isset($this->request->getData()['page']) &&
                    isset($this->request->getData()['user_id'])
                ) {
                    $limit = $this->request->getData('limit');
                    $page = $this->request->getData('page');
                    $userId = $this->request->getData('user_id');

                    $conditions = [
                        'Posts.user_id' => (int) $userId,
                        'Posts.deleted' => false
                    ];

                    $contains = [
                        'RecentComments',
                        'RecentComments.Users' => function (Query $q) {
                            return $q
                                ->select(['id', 'username', 'image', 'deleted']);
                        },
                        'Users' => function (Query $q) {
                            return $q
                                ->select(['id', 'username', 'image', 'deleted']);
                        },
                        'RetweetedPost' => function (Query $q) {
                            return $q
                                ->select(['id', 'post', 'deleted', 'post_image', 'user_id']);
                        },
                        'RetweetedPost.Users',
                        'Likes' => function (Query $q) {
                            return $q
                                ->where(['Likes.user_id' => $this->Auth->user('id')]);
                        }
                    ];
                    $postsTable = TableRegistry::getTableLocator()->get('Posts');

                    //Fetching the posts
                    $posts = $postsTable->fetchPosts($contains, $conditions, $page, $limit);

                    $comments = TableRegistry::getTableLocator()->get('Comments');
                    $newComment = $comments->newEntity();

                    $this->set(['posts' => $posts, 'page' => $page, 'newComment' => $newComment]);
                }
            }
        } catch (MethodNotAllowedException $e) {
            $this->Flash->error(__($e->getMessage()));
            return $this->redirect(['controller' => 'posts', 'action' => 'index']);
        }
    }

    /**
     * Rendering Search User View
     *
     * @return view
     */
    public function searchUser()
    {
        $this->viewBuilder()->setlayout('inside');
        if ($this->request->is(['get'])) {
            $searchWord = $this->request->getQuery('search');
            $this->set(['search' => $searchWord]);
        }
    }

    /**
     * Retrieving User Information for Search User View
     * @throws Cake\Http\Exception\MethodNotAllowedException
     * @return ajax view
     */
    public function fetchSearch()
    {
        try {
            $this->viewBuilder()->setLayout('ajax');
            if (!$this->request->is('ajax')) {
                throw new MethodNotAllowedException('Not allowed to access');
            }
            if ($this->request->is(['post'])) {
                if (
                    isset($this->request->getData()['search']) &&
                    isset($this->request->getData()['limit']) &&
                    isset($this->request->getData()['page'])
                ) {
                    $search = $this->request->getData('search');
                    $limit = $this->request->getData('limit');
                    $page = $this->request->getData('page');

                    $conditions = [
                        [
                            'Users.deleted' => false,
                            'Users.activated' => true,
                            'OR' => [
                                'Users.username LIKE' => '%' . h($search) . '%',
                                'Users.email LIKE' => '%' . h($search) . '%'
                            ]
                        ]
                    ];
                    $usersQuery = $this->Users->find('all')
                        ->where($conditions)
                        ->page($page, $limit)
                        ->limit($limit);

                    $users = $usersQuery->all();

                    $this->set(['users' => $users]);
                }
            }
        } catch (MethodNotAllowedException $e) {
            $this->Flash->error(__($e->getMessage()));
            return $this->redirect(['controller' => 'posts', 'action' => 'index']);
        }
    }
}
