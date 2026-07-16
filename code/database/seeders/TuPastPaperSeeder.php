<?php

namespace Database\Seeders;

use App\Models\Question;
use App\Models\Subject;
use Illuminate\Database\Seeder;

/**
 * Real TU (IOST) BSc CSIT 7th-semester data:
 *
 *  - MGT411 Principles of Management — 12 syllabus units + past-paper questions
 *    from the 2078, 2079, 2080, 2082 and 2083 board exams.
 *  - CSC409 Advanced Java Programming — 8 syllabus units + past-paper questions
 *    from the 2074–2078, 2080, 2082 and 2083 board exams.
 *
 * Question conventions follow the papers themselves: Section/Group A → `long`
 * 10 marks, Section/Group B → `short` 5 marks. Questions repeated across years
 * are stored once with every year listed in `attributes.exam_years`. A handful
 * of old-course questions that no longer map to the current syllabus (JavaBeans,
 * JAR/manifest files) are intentionally omitted. Questions that span two units
 * carry the extra unit through the `question_unit` pivot (syncUnitLinks).
 */
class TuPastPaperSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedPrinciplesOfManagement();
        $this->seedAdvancedJava();
    }

    private function seedPrinciplesOfManagement(): void
    {
        $units = [
            ['The Nature of Organizations', 3, 'Concept of organization. Organizational goals – concept, purposes, and types. Features of effective organizational goals. Goal formulation – processes and approaches. Goal succession and displacement. Problems of goal formulation. Changing perspectives of organization.'],
            ['Introduction to Management', 3, 'Definition, characteristics, and principles of management. Process and functions of management. Managerial hierarchy. Types of managers. Managerial skills and roles. Emerging challenges for management.'],
            ['Evolution of Management Thought', 5, 'Introduction, contribution and limitation of Classical theory, Human relations and Behavioural science theories, System theory, Decision theory, Management science theory, and Contingency theory. Emerging management concepts: workforce diversity, outsourcing, knowledge management, learning organization.'],
            ['Environmental Context of Management', 5, 'Concept of business environment. Types of business environment – internal and external. Basic components of economic, socio-cultural, political, and technological environments. Social responsibility of business – concept and approaches. Areas of social responsibility. Business ethics – meaning and significance. Emerging business environment in Nepal.'],
            ['Planning and Decision Making', 5, 'Concept, types, hierarchy of planning. Process and importance of planning. Strategic planning. Environmental scanning – concept and methods. SWOT analysis. Formulation and implementation of strategic plans. Quantitative tools for planning. Decision making – definition and approaches. Types of decisions. Decision making under conditions of certainty and uncertainty. Problem solving – concepts, types of problem. Problem solving strategies.'],
            ['Organizing Function', 6, 'Concept and principles of organizing. Approaches to organizing – classical, behavioural, and contingency. Process of structuring an organization. Departmentalization – meaning and types. Delegation of authority – meaning, features, advantages, and barriers. Centralization and decentralization – meaning, advantages and disadvantages. Concept of organic and mechanistic views of organization. Types of modern organizational structures – matrix, team, and network.'],
            ['Leadership & Conflict', 3, 'Concept and functions of leadership. Leadership styles. Approaches to leadership – trait, behavioral, and situational. Group formation. Types and characteristics of groups. Conflict – meaning and types. Managing conflicts in organization.'],
            ['Motivation', 3, 'Concept. Theories of motivation – Need Hierarchy, and Motivation-Hygiene. Reward system to motivate performance. Motivation through employee participation – quality of work life, and self-managed teams.'],
            ['Communication', 3, 'Concept, structure, and process. Types of communication – formal and informal. Interpersonal and nonverbal communication. Barriers to effective communication. Enhancing effective communication.'],
            ['Control and Quality Management', 3, 'Concept, process, and types of control systems. Characteristics of effective control system. Quality control systems – concept of quality. Total Quality Management (TQM) – concept and tools. Deming management – principles and techniques.'],
            ['Global Context of Management', 3, 'Concept of globalization. Methods of globalization. Effects of globalization. Multinational companies – meaning, types, advantages, and disadvantages.'],
            ['Management Trends and Scenario in Nepal', 3, 'Growth of business sector in Nepal. Major industries in Nepal – manufacturing, export-oriented, import-substitution, and service sector. Existing management practices and business culture. Major problems of businesses in Nepal.'],
        ];

        // [unit, type, marks, examYears, text, extraUnits?]
        $questions = [
            // Unit 1 — The Nature of Organizations
            [1, 'long', 10, [2078, 2080, 2083], 'Define organizational goals. How are organizational goals formulated?'],
            [1, 'short', 5, [2079], 'Explain goal succession and displacement.'],
            [1, 'short', 5, [2082], 'State and explain the problems of goal formulation.'],

            // Unit 2 — Introduction to Management
            [2, 'long', 10, [2078, 2079], 'State and explain the emerging challenges faced by managers while managing organizations.'],
            [2, 'long', 10, [2080], 'What is management? Describe the principles of management.'],
            [2, 'long', 10, [2082], 'Define management. Describe the functions of management.'],
            [2, 'long', 10, [2083], 'Why are skills important for effective role performance in an organization? Discuss.'],
            [2, 'short', 5, [2078], 'Explain the different skills required for managers.'],

            // Unit 3 — Evolution of Management Thought
            [3, 'short', 5, [2078], 'Describe the human relations theory of management.'],
            [3, 'short', 5, [2079], 'Write why modern organizations are involved in outsourcing.'],
            [3, 'short', 5, [2080], 'What is learning organization? Explain the benefits of learning.'],
            [3, 'short', 5, [2082], 'Briefly describe about system theory of management.'],
            [3, 'short', 5, [2083], 'Describe about diversity and outsourcing.'],

            // Unit 4 — Environmental Context of Management
            [4, 'long', 10, [2078], "What is a business environment? Explain the components of an organization's internal environment."],
            [4, 'long', 10, [2079], 'Define business environment and explain its basic components.'],
            [4, 'long', 10, [2082], 'State and explain the internal components of business environment.'],
            [4, 'short', 5, [2080], 'Write the approaches of social responsibility.'],
            [4, 'short', 5, [2082], 'Mention the main factors of technological environment.'],
            [4, 'short', 5, [2083], 'Discuss the significance of business ethics in an organization.'],
            [4, 'short', 5, [2083], 'State and explain the factors of internal business environment.'],

            // Unit 5 — Planning and Decision Making
            [5, 'long', 10, [2079], 'Explain the importance of planning.'],
            [5, 'long', 10, [2080, 2082], 'What is planning? Discuss the major steps involved in the planning process.'],
            [5, 'short', 5, [2078], 'Write five differences between strategic and tactical planning.'],
            [5, 'short', 5, [2078, 2082], 'Explain the different types of decisions.'],
            [5, 'short', 5, [2079], 'What do you mean by decision making under conditions of uncertainty?'],
            [5, 'short', 5, [2080], 'Mention the types of problems on the basis of urgency.'],
            [5, 'short', 5, [2083], 'Describe the types of plan.'],
            [5, 'short', 5, [2083], 'Discuss the suitable methods for solving problems in an organization.'],

            // Unit 6 — Organizing Function
            [6, 'long', 10, [2078], 'Describe the different approaches to organizing.'],
            [6, 'long', 10, [2082], 'What is departmentalization? Explain the types of departmentalization.'],
            [6, 'short', 5, [2078, 2083], 'What is delegation of authority? Explain the features of delegation of authority.'],
            [6, 'short', 5, [2079], 'Explain the disadvantages of centralization.'],
            [6, 'short', 5, [2080], 'What type of organization formed departmentalization by customers?'],
            [6, 'short', 5, [2082], 'Distinguish between centralization and decentralization of authority.'],
            [6, 'short', 5, [2082], 'Highlight the principles of organization.'],

            // Unit 7 — Leadership & Conflict
            [7, 'short', 5, [2078], 'Explain the different types of leadership styles.'],
            [7, 'short', 5, [2078], 'How are groups formed? Discuss the characteristics of effective groups.'],
            [7, 'short', 5, [2079], 'Write the characteristics of groups.'],
            [7, 'short', 5, [2080], 'Introduce conflict. Explain the different types of conflict.'],

            // Unit 8 — Motivation
            [8, 'short', 5, [2078], 'What is motivation? Explain the importance of reward system to motivate employees in a developing country like Nepal.'],
            [8, 'short', 5, [2079], 'Describe the hierarchy of needs theory.'],
            [8, 'short', 5, [2080], "Define motivation. Explain Herzberg's theory of motivation."],
            [8, 'short', 5, [2080], 'Explain the essentials of effective reward systems.'],
            [8, 'short', 5, [2082], 'Describe the common techniques of employee motivation.'],

            // Unit 9 — Communication
            [9, 'short', 5, [2078], 'What is communication? Write about organizational barriers in communication.'],
            [9, 'short', 5, [2079], 'Explain the interpersonal and nonverbal communication.'],
            [9, 'short', 5, [2080], 'Mention about formal and informal communication.'],
            [9, 'short', 5, [2082], 'State and explain barriers of effective communication.'],
            [9, 'short', 5, [2083], 'State and explain the types of communication.'],

            // Unit 10 — Control and Quality Management
            [10, 'short', 5, [2078, 2079, 2082], 'Explain the characteristics of an effective control system.'],
            [10, 'short', 5, [2080], 'Describe the different types of control system.'],
            [10, 'short', 5, [2083], 'Mention the significance of total quality management (TQM).'],

            // Unit 11 — Global Context of Management
            [11, 'long', 10, [2079], 'What is globalization? Explain the effects of globalization.'],
            [11, 'long', 10, [2083], 'Define globalization. Describe the methods of globalization.'],
            [11, 'short', 5, [2078], 'Define globalization. Explain the effects of globalization in Nepal.'],
            [11, 'short', 5, [2079], 'Discuss the disadvantages of multinational companies.'],
            [11, 'short', 5, [2080], 'Write any four negative effects of globalization.'],
            [11, 'short', 5, [2082], 'Mention the positive effects of globalization.'],

            // Unit 12 — Management Trends and Scenario in Nepal
            [12, 'long', 10, [2080], 'State and explain the major problems facing business in Nepal.'],
            [12, 'short', 5, [2079, 2080], 'Explain the service sector industries in Nepal.'],
            [12, 'short', 5, [2079], 'Write the existing management practices and business culture in Nepal.'],
            [12, 'short', 5, [2082], 'Explain the export-oriented industries of Nepal.'],
            [12, 'short', 5, [2083], 'List out the major problems of export-oriented business of Nepal.'],
        ];

        $this->seedSubject(
            'MGT411',
            'Principles of Management',
            'TU BSc CSIT 7th semester (MGT411). Organization, management functions, planning, organizing, leadership, motivation, communication, control, and the Nepali business context.',
            $units,
            $questions,
        );
    }

    private function seedAdvancedJava(): void
    {
        $units = [
            ['Programming in Java', 8, 'Java Architecture, Java Buzzwords, Path and ClassPath variables, compiling and running Java programs. Arrays, for-each loop, class and object, overloading, access privileges, interface, inner class, final and static modifiers, packages, inheritance, overriding. Handling exceptions: try, catch, finally, throws and throw, creating exception classes. Concurrency: thread states, multithreaded programs, thread properties, synchronization, priorities. Working with files: byte and character stream classes, random access file, reading and writing objects.'],
            ['User Interface Components with Swing', 10, 'Concept of AWT, AWT vs Swing, Java applets, applet life cycle, Swing class hierarchy, components and containers. Layout management: no layout, flow, border, grid, gridbag, and group layout. GUI controls: text fields, password fields, text areas, scroll pane, labels, check boxes, radio buttons, borders, combo boxes, sliders. Menus, menu items, icons, pop-up menus, mnemonics and accelerators, toolbars, tooltips. Option dialogs, creating dialogs, file choosers, color choosers, internal frames, frames, tables, and trees.'],
            ['Event Handling', 4, 'Event handling concept, listener interfaces, using action commands, adapter classes. Handling action events, key events, focus events, mouse events, window events, item events.'],
            ['Database Connectivity', 4, 'JDBC architecture, JDBC driver types, JDBC configuration, managing connections, statements, result set, SQL exceptions. DDL and DML operations using Java, prepared statements, multiple results, scrollable and updateable result sets, row sets and cached row sets, transactions, SQL escapes.'],
            ['Network Programming', 5, 'TCP, UDP, ports, IP address, network classes in JDK. Socket programming using TCP and UDP, working with URLs and the URLConnection class. Java Mail API, sending and receiving email.'],
            ['GUI with JavaFX', 3, 'Introduction, JavaFX vs Swing. JavaFX layouts: FlowPane, BorderPane, HBox, VBox, GridPane. JavaFX UI controls: Label, TextField, Button, RadioButton, CheckBox, Hyperlink, Menu, Tooltip, FileChooser.'],
            ['Servlets and Java Server Pages', 8, 'Web container, introduction to servlets, servlet life cycle, servlet APIs, writing servlet programs, reading form parameters, processing forms, handling HTTP request and response (GET/POST), database access with servlets, handling cookies and sessions. Servlet vs JSP, JSP access model, JSP syntax (directives, declarations, expressions, scriptlets, comments), JSP implicit objects, object scope, processing forms, database access with JSP. Introduction to Java web frameworks.'],
            ['RMI and CORBA', 3, 'Introduction to RMI, RMI architecture, creating and executing RMI applications. Introduction to CORBA, RMI vs CORBA, CORBA architecture, IDL, simple CORBA programs.'],
        ];

        // [unit, type, marks, examYears, text, extraUnits?]
        $questions = [
            // Unit 1 — Programming in Java
            [1, 'long', 10, [2074], 'Define inheritance. Discuss the benefits of using inheritance. Discuss multiple inheritance with suitable example.'],
            [1, 'long', 10, [2075], 'Why do we need to handle the exception? Distinguish between error and exception. Write a program to demonstrate your own exception class.'],
            [1, 'long', 10, [2076], 'What is exception handling? Discuss the use of each keyword (try, catch, throw, throws and finally) with suitable Java program.'],
            [1, 'long', 10, [2077], 'Describe the responsibility of Serializable interface. Write a program to read an input string from the user and write the vowels of that string in VOWEL.TXT and consonants in CONSONANT.TXT.'],
            [1, 'long', 10, [2080], 'Why do we need to synchronize the thread? Justify with an example. An array with an odd number of elements is said to be centered if all elements (except the middle one) are strictly greater than the value of the middle element. Write a function that accepts an integer array and returns 1 if it is a centered array, otherwise it returns 0.'],
            [1, 'short', 5, [2074], 'Write an object oriented program to find area and perimeter of rectangle.'],
            [1, 'short', 5, [2074], 'Write a simple Java program that reads data from one file and writes the data to another file.'],
            [1, 'short', 5, [2075], 'An array is called balanced if its even-numbered elements (a[0], a[2], etc.) are even and its odd-numbered elements (a[1], a[3], etc.) are odd. Write a function named isBalanced that accepts an array of integers and returns 1 if the array is balanced, otherwise it returns 0.'],
            [1, 'short', 5, [2075], 'Define the chain of constructor. What is the purpose of private constructor?'],
            [1, 'short', 5, [2075], 'Write down the life cycle of thread. Write a program to execute multiple threads in priority base.'],
            [1, 'short', 5, [2075], 'When do we use final method and final class? Differentiate between function overloading and function overriding.'],
            [1, 'short', 5, [2076], 'Define class. How do you create a class in Java? Differentiate class with interface.'],
            [1, 'short', 5, [2076], 'Write a simple Java program that reads a file named "Test.txt" and displays its contents.'],
            [1, 'short', 5, [2077], 'A non-empty array A of length n is called an array of all possibilities if it contains all numbers between 0 and A.length-1 inclusive. Write a method named isAllPossibilities that accepts an integer array and returns 1 if the array is an array of all possibilities, otherwise it returns 0.'],
            [1, 'short', 5, [2077], 'When does the finally block become mandatory while handling an exception? Describe with a suitable scenario.'],
            [1, 'short', 5, [2077], 'Why is multiple inheritance not allowed in Java using classes? Give an example.'],
            [1, 'short', 5, [2078], 'What is package? How can you create your own package in Java? Explain with example.'],
            [1, 'short', 5, [2080], 'Suppose that 9 integers are written in a file named "magic.txt" in an arrangement of 3 x 3 separated by spaces. Write a program to check whether the integers in all rows, all columns and both diagonals sum to the same constant or not.'],
            [1, 'short', 5, [2082], 'Write a program to input the name of faculty and throw an exception if that input is not "CSIT".'],
            [1, 'short', 5, [2082], 'What is package? Differentiate between method overloading and overriding.'],
            [1, 'short', 5, [2082], 'Write a program to create a class MOVIE with attributes name and genre. Write the movies with genre comedy on COM.DAT file.'],
            [1, 'short', 5, [2083], 'Explain method overloading with suitable program.'],
            [1, 'short', 5, [2083], 'How do you write programs with multiple threads in Java?'],
            [1, 'short', 5, [2074], 'Write short notes on: (a) Multithreading (b) JSP', [7]],
            [1, 'short', 5, [2083], 'Write short notes on: (a) Inner class (b) CachedRowSet', [4]],

            // Unit 2 — User Interface Components with Swing
            [2, 'long', 10, [2074], 'Write a program using swing components to find simple interest. Use text fields for inputs and output. Your program should display output if the user clicks a button.'],
            [2, 'long', 10, [2075], 'Design a GUI form using swing with a text field, a text label for displaying the input message "Input any string", and three buttons with captions Check Palindrome, Reverse, and Find Vowels. Write a complete program to check palindrome on the first button, reverse the string on the second button, and extract the vowels on the third button.'],
            [2, 'long', 10, [2076], 'Write a Java program to find the sum of two numbers using swing components. Use text fields for input and output. Your program displays output if you press any key in the keyboard. Use key adapter to handle events.', [3]],
            [2, 'long', 10, [2078], 'Compare AWT with Swing. Write a GUI program using swing components to find sum and difference of two numbers. Use two text fields for giving input and a label for output. The program should display sum if user presses mouse and difference if user releases mouse.', [3]],
            [2, 'long', 10, [2080], 'Describe any two types of layout manager. Using swing components design a form with three buttons with captions "RED", "BLUE" and "GREEN" respectively. Then write a program to handle the event such that when the user clicks a button, the color of that button becomes the same as its caption.', [3]],
            [2, 'short', 5, [2074], 'Discuss grid layout with example.'],
            [2, 'short', 5, [2076], 'Why do we need layout management? What is GridBag layout?'],
            [2, 'short', 5, [2077], 'What is the task of layout manager? Describe the default layout manager.'],
            [2, 'short', 5, [2078], 'Why do we need swing components? Explain the uses of check boxes and radio buttons in GUI programming.'],
            [2, 'short', 5, [2080], 'When do we need internal frame? How do you create a table using swing?'],
            [2, 'short', 5, [2080], 'Write a program to create a menu named "File" with menu items "New", "Save" and "Exit".'],
            [2, 'short', 5, [2082], 'Write a program to insert an icon in the frame and when the user presses the up arrow, it will move upward.', [3]],
            [2, 'short', 5, [2082], 'Write a program to design a layout of a simple calculator. You do not need to show the arithmetic operation.'],
            [2, 'short', 5, [2082], 'Write a program to demonstrate the concept of internal frame.'],
            [2, 'short', 5, [2082], 'Do we still need Java applets? Justify. Give the hierarchy of swing classes.'],
            [2, 'short', 5, [2083], 'Explain GroupLayout with example.'],
            [2, 'short', 5, [2075], 'Write short notes on: (a) Grid Layout (b) Ragged Array', [1]],

            // Unit 3 — Event Handling
            [3, 'long', 10, [2074], 'Why do we need event handling? Discuss the process of handling events with example. Differentiate event listener interface with adapter class.'],
            [3, 'short', 5, [2074], 'What is action event? Discuss.'],
            [3, 'short', 5, [2077], 'Define event delegation model. Why do we need adapter class in event handling?'],
            [3, 'short', 5, [2078], 'How can we use listener interface to handle events? Compare listener interface with adapter class.'],
            [3, 'short', 5, [2083], 'Explain event delegation model. Compare adapter class with listener interface in event handling.'],

            // Unit 4 — Database Connectivity
            [4, 'long', 10, [2077], 'You are hired by a reputed software company which is going to design an application for "Movie Rental System". Your responsibility is to design a schema named MRS and create a table named Movie(id, Title, Genre, Language, Length). Write a program to design a GUI form to take input for this table and insert the data into the table after clicking the OK button.', [2]],
            [4, 'long', 10, [2082], "Discuss about JSP implicit objects. Assume a database with the table TEACHER (ID, Name). Now using JDBC, execute the following SQL queries: (a) select * from TEACHER; (b) insert into TEACHER values (8, 'Ramesh'); (c) select name from TEACHER where ID = 9;", [7]],
            [4, 'long', 10, [2083], 'Describe JDBC connectivity steps with example. Create a Java application using swing and JDBC for login form with username and password.', [2]],
            [4, 'short', 5, [2074], 'How do you execute SQL statements in JDBC?'],
            [4, 'short', 5, [2075], 'How are prepared statements different from statements? List the types of JDBC drivers.'],
            [4, 'short', 5, [2076], 'What are the benefits of using JDBC? What is prepared statement?'],
            [4, 'short', 5, [2078], 'What is row set? Explain cached row set in detail.'],
            [4, 'short', 5, [2080], 'Assume a table MOVIE(id, title, genre). Now using JDBC, perform the following queries: (a) add any three records to the MOVIE table; (b) using prepared statement, update the genre to "Comedy" having title "Jatra".'],
            [4, 'short', 5, [2080], 'Describe the role of Result Sets. What is wrong in the following code? public class Point { int p; public void setP(int p) { p = p; } }', [1]],
            [4, 'short', 5, [2083], 'Explain prepared statement with suitable program.'],
            [4, 'short', 5, [2078], 'Write short notes on: (a) JDBC drivers (b) Java server pages', [7]],

            // Unit 5 — Network Programming
            [5, 'long', 10, [2082], 'What are the uses of focus and item event? Write a socket program using UDP to create three programs, two of which are clients to a single server. Client1 will send a character to the server process. The server will circularly decrement the letter to the previous letter in the alphabet and send the result to Client2. Then Client2 prints the letter it receives and then all the processes terminate.', [3]],
            [5, 'short', 5, [2074], 'What is socket? How can you write Java programs that communicate with each other using TCP sockets?'],
            [5, 'short', 5, [2075], 'What is a socket? Write client and server programs in which a server program accepts a radius of a circle from the client program, computes the area, sends the computed area to the client program, and the client program displays it.'],
            [5, 'short', 5, [2076], 'Write Java programs using TCP sockets that communicate with each other in a computer network.'],
            [5, 'short', 5, [2078], 'What is Java Mail API? How can you use this API to send email messages?'],
            [5, 'short', 5, [2080], 'Write a TCP client-server system in which the client program sends two integers to a server program which returns the greatest among them.'],
            [5, 'short', 5, [2083], 'Explain different classes available in Java for socket programming using UDP connection.'],

            // Unit 6 — GUI with JavaFX
            [6, 'long', 10, [2082], 'Write a JavaFX application that creates a ChoiceBox with a list of colors. Display a label that changes its text based on the selected color from the ChoiceBox. Write down steps for writing CORBA programs with suitable example.', [8]],
            [6, 'short', 5, [2078], 'Compare JavaFX with swing. Explain HBox and VBox layouts of JavaFX.'],
            [6, 'short', 5, [2080], 'Write a JavaFX application with components buttons, textfields and labels, arranged in a VBox or HBox layout.'],
            [6, 'short', 5, [2083], 'Explain steps of writing JavaFX programs with suitable program.'],

            // Unit 7 — Servlets and Java Server Pages
            [7, 'long', 10, [2075], 'Describe the process to deploy the servlet. Write a program to create a JSP web form to take input of a student and submit it to a second JSP file which simply prints the values of the form submission.'],
            [7, 'long', 10, [2076], "Define servlet. Discuss life cycle of servlets. Differentiate servlet with JSP. Write a simple JSP file to display 'IOST' 20 times."],
            [7, 'long', 10, [2078], 'Explain life-cycle of servlet in detail. Create a simple servlet that reads and displays data from HTML form. Assume a HTML form with two fields username and password.'],
            [7, 'long', 10, [2080], 'How does JSP differ from Servlet and show the life cycle of Servlet? How do you create and read cookies and session using JSP? Illustrate with an example.'],
            [7, 'long', 10, [2083], 'List different implicit objects in JSP with short description of each. Write a JSP program to accept user input from HTML form with two fields (Username and Password) and display the result.'],
            [7, 'short', 5, [2074], 'Write a Java program using servlet to display "Tribhuvan University".'],
            [7, 'short', 5, [2075], 'Explain the significance of cookies and sessions with suitable example.'],
            [7, 'short', 5, [2076], 'Discuss MVC design pattern with example.'],
            [7, 'short', 5, [2076], 'How do you handle HTTP request (GET) using servlet?'],
            [7, 'short', 5, [2077, 2082], 'Explain the life cycle of a servlet.'],
            [7, 'short', 5, [2077], 'How can forms be created and processed using JSP? Make it clear with your own assumptions.'],
            [7, 'short', 5, [2078], 'What is servlet? Write a simple JSP file to display "Tribhuvan University" five times.'],
            [7, 'short', 5, [2080], 'What do you mean by JSP implicit objects? Discuss about Java Mail API.', [5]],
            [7, 'short', 5, [2082], 'How do you handle HTTP request and response using JSP? Illustrate with an example.'],
            [7, 'short', 5, [2076], 'Write short notes on: (a) Servlet API (b) RMI vs CORBA', [8]],

            // Unit 8 — RMI and CORBA
            [8, 'long', 10, [2077], 'What is the significance of stub and skeleton in RMI? Create a RMI application such that a client sends an integer number to the server and the server returns the factorial value of that integer. Give a clear specification for every step.'],
            [8, 'long', 10, [2078], 'Explain RMI architecture layers in detail. Write a Java program using RMI to find product of two numbers.'],
            [8, 'long', 10, [2083], 'How is RMI different from socket programming? Write a Java RMI program to perform addition of two numbers entered by the client and display the result returned by the server.', [5]],
            [8, 'short', 5, [2074], 'What is CORBA? How is it different from RMI?'],
            [8, 'short', 5, [2075], 'Describe the process to run the RMI application.'],
            [8, 'short', 5, [2076], 'What are different layers of RMI architecture? Explain.'],
            [8, 'short', 5, [2078], 'Why is CORBA important? Compare CORBA with RMI.'],
            [8, 'short', 5, [2080], 'List the steps to create RMI application. Differentiate between RMI and CORBA.'],
            [8, 'short', 5, [2083], 'What are the functions of each layer in RMI?'],
        ];

        $this->seedSubject(
            'CSC409',
            'Advanced Java Programming',
            'TU BSc CSIT 7th semester (CSC409). GUI and event-driven programming with Swing/JavaFX, JDBC, socket programming, servlets and JSP, and distributed programming with RMI/CORBA.',
            $units,
            $questions,
        );
    }

    /**
     * @param  array<int, array{0:string,1:int,2:string}>  $unitRows  [name, hours, content]
     * @param  array<int, array{0:int,1:string,2:int,3:string,4:int[],5:string,6?:int[]}>  $questionRows
     */
    private function seedSubject(string $code, string $name, string $description, array $unitRows, array $questionRows): void
    {
        $syllabus = "## $code – $name\n\n".collect($unitRows)
            ->map(fn ($row, $i) => '### Unit '.($i + 1).": {$row[0]} ({$row[1]} Hrs.)\n\n{$row[2]}")
            ->implode("\n\n");

        $subject = Subject::updateOrCreate(
            ['code' => $code],
            ['name' => $name, 'description' => $description, 'syllabus' => $syllabus]
        );

        // Idempotent reseed: clear existing units/questions for this subject.
        $subject->questions()->delete();
        $subject->units()->delete();

        $unitIds = [];
        foreach ($unitRows as $i => [$unitName, $hours, $content]) {
            $unitIds[$i + 1] = $subject->units()->create([
                'name' => $unitName,
                'position' => $i + 1,
                'hours' => $hours,
                'content' => $content,
            ])->id;
        }

        foreach ($questionRows as $row) {
            [$unit, $type, $marks, $years, $text] = $row;
            $extraUnits = $row[5] ?? [];

            $question = Question::create([
                'subject_id' => $subject->id,
                'unit_id' => $unitIds[$unit],
                'type' => $type,
                'marks' => $marks,
                'text' => $text,
                'source' => 'manual',
                'status' => 'approved',
                'attributes' => ['exam_years' => $years, 'exam_board' => 'TU IOST'],
                'used_count' => 0,
            ]);

            $question->syncUnitLinks(array_map(
                fn (int $u) => $unitIds[$u],
                array_values(array_unique([$unit, ...$extraUnits])),
            ));
        }

        $this->command?->info("Seeded $code: ".count($unitRows).' units, '.count($questionRows).' questions.');
    }
}
