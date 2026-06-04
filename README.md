# TaskFlow – Student Task & Project Manager

TaskFlow is a modern web-based task and project management system designed for students. It helps users organize projects, manage tasks, track progress, and monitor upcoming deadlines through an intuitive dashboard and Kanban-style workflow.

## Features

### User Authentication

* User registration and login
* Secure password hashing using PHP password hashing functions
* Session-based authentication
* Personalized user profiles

### Project Management

* Create and manage multiple projects
* Custom project colors
* Project descriptions
* Project progress tracking
* Delete projects and associated tasks

### Task Management

* Create tasks within projects
* Set task priorities (Low, Medium, High)
* Add task descriptions
* Set due dates
* Update task status
* Delete tasks

### Kanban Board

Tasks are organized into three workflow stages:

* To Do
* In Progress
* Done

This allows users to visually track project progress and productivity.

### Dashboard & Analytics

* Total project count
* Completed tasks statistics
* Tasks in progress
* Pending tasks
* Upcoming deadlines
* Progress indicators

### Responsive Design

* Desktop-friendly interface
* Mobile-responsive layout
* Modern dark-themed user interface
* Interactive notifications and alerts

## Technologies Used

### Backend

* PHP 8+
* SQLite Database
* PDO (PHP Data Objects)

### Frontend

* HTML5
* CSS3
* JavaScript (Vanilla JS)

### Database

* SQLite

## Database Structure

### Users Table

Stores:

* User information
* Email addresses
* Password hashes
* Avatar colors
* Account creation dates

### Projects Table

Stores:

* Project details
* Descriptions
* Colors
* User ownership

### Tasks Table

Stores:

* Task information
* Priorities
* Status
* Due dates
* Project relationships

## Installation

1. Clone the repository

```bash
git clone https://github.com/BravoOdhiambo/student-task-manager.git
```

2. Navigate into the project folder

```bash
cd student-task-manager
```

3. Start a PHP server

```bash
php -S localhost:8000
```

4. Open your browser

```text
http://localhost:8000
```

## Project Structure

```text
student-task-manager/
│
├── index.php        # Main application file
├── taskflow.db      # SQLite database (generated automatically)
└── README.md
```

## Security Features

* Password hashing
* Session management
* Input validation
* Email validation
* SQL injection protection through prepared statements

## Future Improvements

* Task editing functionality
* Team collaboration
* File attachments
* Email notifications
* Calendar integration
* Dark/Light theme switching
* Data export features

## Author

Bravo Odhiambo

## License

This project was developed as an academic assignment for educational purposes.
