# Gutenberg Blocks

This plugin provides several interactive Gutenberg blocks for community engagement on your WordPress site. All blocks use the `extrachill/` namespace and are designed to enhance user interaction and engagement.

## Available Blocks

### Band Name Generator

**Block Name**: `extrachill/band-name-generator`

Generates random band names based on selected music genres. Perfect for music blogs, entertainment sites, or creative writing prompts.

**Features**:
- Multiple genre options (Rock, Country, Metal, Indie, Punk, Jam, Electronic, Random)
- Random name generation with one click
- Customizable button text and styling
- Word count selection (2-4 words)
- Optional "The" prefix and "& The" insertions

**Usage**:
1. Add the "Band Name Generator" block to your post or page
2. Select your preferred genres from the block settings
3. The block will display a generate button and show random band names

### Rapper Name Generator

**Block Name**: `extrachill/rapper-name-generator`

Creates random rapper/artist names with various styles and themes. Great for hip-hop content, music discovery, or entertainment features.

**Features**:
- Multiple style categories (Old School, Trap, Grime, Conscious, Random)
- Gender-based name generation (Male, Female, Non-binary)
- Instant name generation
- Responsive design for all devices

**Usage**:
1. Insert the "Rapper Name Generator" block
2. Choose style preferences in the block editor
3. Users can generate new names with a single click

### Image Voting

**Block Name**: `extrachill/image-voting`

Allows community voting on images with email capture functionality. Ideal for contests, polls, or audience engagement.

**Features**:
- Single image voting with overlay display
- Email collection for voter registration
- Vote tracking and display
- Unique block instance identification

**Usage**:
1. Add the "Image Voting" block to your content
2. Upload images for voting in the block settings
3. Configure email capture and voting options
4. Display shows current vote counts and allows user participation

### Trivia

**Block Name**: `extrachill/trivia`

Interactive trivia questions with multiple choice answers and automatic scoring. Perfect for educational content, quizzes, or entertainment.

**Features**:
- Multiple choice questions with customizable number of options
- Automatic scoring and feedback
- Custom result messages based on performance ranges
- Answer justifications for learning
- Configurable score ranges for different result messages

**Usage**:
1. Insert the "Trivia Question" block
2. Enter your question and answer options
3. Select the correct answer
4. Add justification text for the correct answer
5. Customize result messages for different score ranges

### AI Adventure

**Block Name**: `extrachill/ai-adventure`

Text-based adventure game with branching paths and AI-generated storytelling. Creates immersive interactive experiences.

**Features**:
- Branching narrative paths
- AI-powered story generation
- User choice-driven progression
- Customizable opening scenarios

**Usage**:
1. Add the "AI Adventure" block
2. Configure the opening screen and initial prompt
3. Set up adventure paths and steps
4. Users navigate through the story by making choices

### AI Adventure Path

**Block Name**: `extrachill/ai-adventure-path`

Structural component for organizing adventure game narratives. Used in conjunction with AI Adventure blocks.

**Features**:
- Path-based story organization
- Sequential step management
- Integration with AI Adventure system

### AI Adventure Step

**Block Name**: `extrachill/ai-adventure-step`

Individual interaction points within adventure games. Defines specific choices and outcomes.

**Features**:
- Step-by-step adventure progression
- Choice and consequence definition
- Seamless integration with adventure paths

## Block Management

All blocks are versioned independently (currently v1.1.0) and use the `extrachill/` namespace. They are built with WordPress's official @wordpress/scripts and follow WordPress coding standards.

## Technical Notes

- Blocks automatically detect development vs production environments
- In development: loads from `src/blocks/`
- In production: loads from `build/blocks/` (after running `npm run build`)
- All blocks include proper accessibility features and responsive design