#!/bin/bash

composer lint

npm run format

npm run lint

composer test

