<?php
// deck_builder.php
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Yugioh Duelist of the Roses Deck Builder</title>
    <style>
        /* Basic Reset and Body Styles */
        body {
            margin: 0;
            padding: 0;
            font-family: sans-serif;
            background: #222;
            color: #eee;
        }
        h1, h2 {
            text-align: center;
        }
        /* Container for the two columns */
        .container {
            display: flex;
            height: 80vh;
        }
        .available-cards, .deck {
            padding: 10px;
            overflow-y: auto;
        }
        .available-cards {
            width: 50%;
            border-right: 1px solid #555;
        }
        .deck {
            width: 50%;
        }
        /* Card style for available cards and deck cards */
        .card {
            background: #333;
            border: 1px solid #444;
            margin: 5px;
            padding: 5px;
            cursor: pointer;
        }
        .card:hover {
            background: #444;
        }
        /* Card details area */
        .card-details {
            padding: 10px;
            border-top: 1px solid #555;
        }
        .card-details img {
            width: 120px;
            height: auto;
            display: block;
            margin-bottom: 10px;
        }
        .card-row {
            margin: 5px 0;
        }
        /* Style for deck cards – include a remove button */
        .deck .card {
            position: relative;
            cursor: default;
        }
        .deck .card button {
            position: absolute;
            right: 5px;
            top: 5px;
            background: #c00;
            color: #fff;
            border: none;
            padding: 2px 5px;
            cursor: pointer;
        }
        .deck .card button:hover {
            background: #e00;
        }
    </style>
</head>
<body>
    <h1>Yugioh Duelist of the Roses Deck Builder</h1>
    <div class="container">
        <!-- Left Column: Available Cards -->
        <div class="available-cards">
            <h2>Available Cards</h2>
            <?php
            // Define an array of sample cards with details.
            $cards = [
                [
                    "id"       => 1,
                    "name"     => "Blue-Eyes White Dragon",
                    "atk"      => 3000,
                    "def"      => 2500,
                    "deck_cost"=> 8,
                    "stars"    => 8,
                    "image"    => "images/blue_eyes.jpg",
                    "info"     => "Legendary dragon with immense power."
                ],
                [
                    "id"       => 2,
                    "name"     => "Dark Magician",
                    "atk"      => 2500,
                    "def"      => 2100,
                    "deck_cost"=> 7,
                    "stars"    => 7,
                    "image"    => "images/dark_magician.jpg",
                    "info"     => "Powerful spellcaster with ancient secrets."
                ],
                [
                    "id"       => 3,
                    "name"     => "Red-Eyes Black Dragon",
                    "atk"      => 2400,
                    "def"      => 2000,
                    "deck_cost"=> 7,
                    "stars"    => 7,
                    "image"    => "images/red_eyes.jpg",
                    "info"     => "A ferocious dragon with a fiery temper."
                ],
                [
                    "id"       => 4,
                    "name"     => "Summoned Skull",
                    "atk"      => 2500,
                    "def"      => 1200,
                    "deck_cost"=> 6,
                    "stars"    => 6,
                    "image"    => "images/summoned_skull.jpg",
                    "info"     => "A fiendish monster with incredible attack power."
                ],
                [
                    "id"       => 5,
                    "name"     => "Exodia the Forbidden One",
                    "atk"      => 1000,
                    "def"      => 1000,
                    "deck_cost"=> 5,
                    "stars"    => 3,
                    "image"    => "images/exodia.jpg",
                    "info"     => "One of the most powerful forbidden cards."
                ]
            ];

            // Output each available card.
            foreach ($cards as $card) {
                // Encode the card data into JSON for use by JavaScript.
                $cardData = htmlspecialchars(json_encode($card), ENT_QUOTES, 'UTF-8');
                echo '<div class="card" data-card=\'' . $cardData . '\'>' . $card["name"] . '</div>';
            }
            ?>
        </div>
        <!-- Right Column: Your Deck -->
        <div class="deck">
            <h2>Your Deck (Max 40 Cards)</h2>
            <div id="deckContainer">
                <!-- Deck cards will be dynamically appended here -->
            </div>
        </div>
    </div>

    <!-- Card Details Section -->
    <div class="card-details" id="cardDetails">
        <h2>Card Details</h2>
        <div id="detailsContent">
            Click on a card to view its details.
        </div>
    </div>

    <script>
        // Get references to elements.
        const availableCards = document.querySelectorAll('.available-cards .card');
        const detailsContent = document.getElementById('detailsContent');
        const deckContainer = document.getElementById('deckContainer');
        let deck = [];

        // Add click event listeners to each available card.
        availableCards.forEach(card => {
            card.addEventListener('click', function() {
                // Parse the card data from the data attribute.
                let cardData = JSON.parse(this.getAttribute('data-card'));
                // Update the card details section with the card information.
                detailsContent.innerHTML = `
                    <img src="${cardData.image}" alt="${cardData.name}">
                    <div class="card-row"><strong>Name:</strong> ${cardData.name}</div>
                    <div class="card-row"><strong>ATK:</strong> ${cardData.atk}</div>
                    <div class="card-row"><strong>DEF:</strong> ${cardData.def}</div>
                    <div class="card-row"><strong>Deck Cost:</strong> ${cardData.deck_cost}</div>
                    <div class="card-row"><strong>Stars:</strong> ${cardData.stars}</div>
                    <div class="card-row"><strong>Info:</strong> ${cardData.info}</div>
                    <button onclick='addToDeck(${JSON.stringify(cardData)})'>Add to Deck</button>
                `;
            });
        });

        // Function to add a card to the deck.
        function addToDeck(cardData) {
            if (deck.length >= 40) {
                alert("Your deck is full! Maximum of 40 cards allowed.");
                return;
            }
            deck.push(cardData);
            updateDeck();
        }

        // Function to update the deck display.
        function updateDeck() {
            deckContainer.innerHTML = "";
            deck.forEach((card, index) => {
                deckContainer.innerHTML += `
                    <div class="card" data-index="${index}">
                        ${card.name}
                        <button onclick="removeFromDeck(${index})">X</button>
                    </div>
                `;
            });
        }

        // Function to remove a card from the deck.
        function removeFromDeck(index) {
            deck.splice(index, 1);
            updateDeck();
        }
    </script>
</body>
</html>
