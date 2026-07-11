/*
 * Headless ALTCHA-Proof-of-Work-Solver. Holt eine frische Challenge vom Bundle-Endpoint, laesst den
 * SHA-256-PoW off-thread im worker.js rechnen und schreibt die Loesung in ein Hidden-Field. Schlaegt
 * Fetch oder PoW fehl, bleibt das Feld leer -> der Server entscheidet; der Submit wird NIE blockiert.
 */
(function () {
  'use strict';

  // Pro Input eine Generation-ID: bei einem Re-Solve (pageshow/Intervall) gewinnt nur der juengste Lauf,
  // ein ueberholter Worker schreibt seine (evtl. schon veraltete) Loesung nicht mehr ins Feld.
  var generation = new WeakMap();

  function solve(input) {
    var gen = (generation.get(input) || 0) + 1;
    generation.set(input, gen);
    input.value = '';   // alten/verbrauchten Proof sofort entfernen, bis die frische Loesung vorliegt

    fetch(input.dataset.challengeurl, { credentials: 'same-origin' })
      .then(function (response) {
        if (!response.ok) {
          throw new Error('challenge request failed');
        }
        return response.json();
      })
      .then(function (challenge) {
        if (generation.get(input) !== gen) {
          return;   // ueberholt (neuer Re-Solve gestartet) -> abbrechen
        }
        if (!challenge || !challenge.challenge || typeof challenge.maxnumber !== 'number') {
          throw new Error('invalid challenge');   // Fehlerbody (WAF/Maintenance) -> keinen Worker starten
        }

        var worker = new Worker(input.dataset.workerurl);
        var stop = function () {
          try { worker.terminate(); } catch (e) { /* egal */ }
        };

        worker.onmessage = function (event) {
          if (generation.get(input) === gen && event.data && typeof event.data.number === 'number') {
            input.value = btoa(JSON.stringify({
              algorithm: challenge.algorithm,
              challenge: challenge.challenge,
              number: event.data.number,
              salt: challenge.salt,
              signature: challenge.signature
            }));
          }
          stop();
        };
        // Wirft der PoW (Web Crypto im Worker doch nicht verfuegbar), Worker beenden statt liegen lassen.
        worker.onerror = stop;
        worker.onmessageerror = stop;

        worker.postMessage({
          type: 'work',
          payload: { algorithm: challenge.algorithm, challenge: challenge.challenge, salt: challenge.salt },
          max: challenge.maxnumber,
          start: 0
        });
      })
      .catch(function () { /* leer lassen: der Server entscheidet, der Submit wird nie blockiert */ });
  }

  function solveAll() {
    document.querySelectorAll('input[data-mandrael-altcha]').forEach(solve);
  }

  solveAll();

  // Nach einer bfcache-Wiederherstellung (Zurueck/Vorwaerts) wurde das Script nicht neu ausgefuehrt und
  // die geladene Loesung ist evtl. abgelaufen oder bereits verbraucht -> mit frischer Challenge neu loesen.
  window.addEventListener('pageshow', function (event) {
    if (event.persisted) {
      solveAll();
    }
  });

  // Lange offene Formulare: die Loesung vor Ablauf der serverseitigen Expiry (3600 s) erneuern. Feuert bei
  // kurzen Sitzungen nie.
  setInterval(solveAll, 45 * 60 * 1000);
})();
