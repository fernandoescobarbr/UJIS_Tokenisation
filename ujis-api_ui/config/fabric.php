<?php

/**
 * Fabric configuration
 *
 * Purpose:
 * - Centralises Fabric network settings used by FabricService to run peer CLI commands.
 * - Values are primarily driven by environment variables for portability.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Fabric chain / channel / chaincode names
    |--------------------------------------------------------------------------
    */
    'channel' => env('FABRIC_CHANNEL', 'ujis-channel'),
    'cc_name' => env('FABRIC_CC_NAME', 'basic'),

    /*
    |--------------------------------------------------------------------------
    | Shell bootstrap (source env + select organisation)
    |
    | These are executed inside:
    |   bash -lc "source <env_script>; <env_fn>; <cmd>"
    |--------------------------------------------------------------------------
    */
    'env_script' => env('FABRIC_ENV_SCRIPT', '/Users/fernandoescobar/go/src/github.com/fernandoescobarbr/fabric-samples/test-network/scripts/envVar.sh'),
    'env_fn'     => env('FABRIC_ENV_FN', 'setGlobals 1'),

    // Absolute paths used by the service to prepare the shell environment.
    'workdir'  => env('FABRIC_WORKDIR', '/Users/fernandoescobar/go/src/github.com/fernandoescobarbr/fabric-samples/test-network'),
    'bin_path' => env('FABRIC_BIN_PATH', '/Users/fernandoescobar/go/src/github.com/fernandoescobarbr/fabric-samples/bin'),
    'cfg_path' => env('FABRIC_CFG_PATH', '/Users/fernandoescobar/go/src/github.com/fernandoescobarbr/fabric-samples/config'),

    /*
    |--------------------------------------------------------------------------
    | Base command prefixes
    |--------------------------------------------------------------------------
    */
    'query_prefix'  => env('FABRIC_QUERY_PREFIX', 'peer chaincode query'),
    'invoke_prefix' => env('FABRIC_INVOKE_PREFIX', 'peer chaincode invoke -o localhost:7050 --ordererTLSHostnameOverride orderer.example.com --tls --cafile /Users/fernandoescobar/go/src/github.com/fernandoescobarbr/fabric-samples/test-network/organizations/ordererOrganizations/example.com/orderers/orderer.example.com/msp/tlscacerts/tlsca.example.com-cert.pem --peerAddresses localhost:7051 --tlsRootCertFiles /Users/fernandoescobar/go/src/github.com/fernandoescobarbr/fabric-samples/test-network/organizations/peerOrganizations/org1.example.com/peers/peer0.org1.example.com/tls/ca.crt --peerAddresses localhost:9051 --tlsRootCertFiles /Users/fernandoescobar/go/src/github.com/fernandoescobarbr/fabric-samples/test-network/organizations/peerOrganizations/org2.example.com/peers/peer0.org2.example.com/tls/ca.crt'),

    /*
    |--------------------------------------------------------------------------
    | Wait for commit events on invoke
    | (Fabric supports --waitForEvent; improves UX determinism)
    |--------------------------------------------------------------------------
    */
    'wait_for_event' => env('FABRIC_WAIT_FOR_EVENT', true),
    'wait_timeout'   => env('FABRIC_WAIT_TIMEOUT', 120),

    /*
    |--------------------------------------------------------------------------
    | JSON decode flags
    |--------------------------------------------------------------------------
    */
    'json_assoc' => true,
    'json_depth' => 512,
];
